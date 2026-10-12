<?php

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateLifecycleOperation;
use App\Actions\Node\CreateMoveOperation;
use App\Actions\Node\CreateNodeCluster;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Jobs\DeleteNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Jobs\MoveNodeWorkloadJob;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\User;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'instance_uuid' => 'instance-test']);
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'test-token');
    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
    $key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Interruption mesh');
    $this->source = Node::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $key->id, 'name' => 'Source']);
    $this->target = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $key->id,
        'name' => 'Target',
        'is_usable' => true,
        'is_reachable' => true,
        'metadata' => [
            'cpu_usage_percent' => 10,
            'memory_bytes' => 1_000,
            'memory_used_bytes' => 100,
            'disk_total_bytes' => 1_000,
            'disk_available_bytes' => 900,
            'collected_at' => now()->toIso8601String(),
        ],
    ]);
    AssignNodeToCluster::run($cluster, $this->source);
    AssignNodeToCluster::run($cluster, $this->target);
    $cluster->update(['network_status' => 'active']);
    $this->workload = NodeWorkload::factory()->create(['team_id' => $this->team->id]);
    $this->source->workloads()->attach($this->workload);
    $this->revision = NodeWorkloadRevision::factory()->create(['node_workload_id' => $this->workload->id]);
    Queue::fake();
});

it('stops waiting when the target deployment becomes uncertain and detaches the target', function () {
    Http::fake(fn () => throw new ConnectionException('Flux is unreachable.'));
    Sleep::fake();
    $operation = CreateMoveOperation::run($this->source, $this->target, $this->revision, $this->user);

    (new MoveNodeWorkloadJob($operation->id))->handle();

    expect(NodeOperation::query()->where('command_type', 'workload.deploy.v1')->sole()->status)->toBe(NodeOperationStatus::UNCERTAIN)
        ->and($operation->refresh()->status)->toBe(NodeOperationStatus::FAILED)
        ->and($this->target->workloads()->whereKey($this->workload->id)->exists())->toBeFalse()
        ->and($this->source->workloads()->whereKey($this->workload->id)->exists())->toBeTrue();
    Sleep::assertNeverSlept();
});

it('detaches a target that the move attached when the move worker is killed', function () {
    $operation = CreateMoveOperation::run($this->source, $this->target, $this->revision, $this->user);
    // The worker attached the target and was killed while the target deployment ran.
    NodeOperation::query()->whereKey($operation->id)->update(['status' => NodeOperationStatus::RUNNING, 'result' => ['target_attached' => true]]);
    $this->target->workloads()->attach($this->workload);

    (new MoveNodeWorkloadJob($operation->id))->failed(new RuntimeException('Job timed out.'));

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::FAILED)
        ->and($this->target->workloads()->whereKey($this->workload->id)->exists())->toBeFalse()
        ->and($this->source->workloads()->whereKey($this->workload->id)->exists())->toBeTrue();
});

it('keeps a target that was assigned before the move when the move worker is killed', function () {
    $this->target->workloads()->attach($this->workload);
    $operation = CreateMoveOperation::run($this->source, $this->target, $this->revision, $this->user);
    NodeOperation::query()->whereKey($operation->id)->update(['status' => NodeOperationStatus::RUNNING]);

    (new MoveNodeWorkloadJob($operation->id))->failed(new RuntimeException('Job timed out.'));

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::FAILED)
        ->and($this->target->workloads()->whereKey($this->workload->id)->exists())->toBeTrue();
});

it('moves an in-flight removal out of running and notifies the team when the delete worker is killed', function () {
    Notification::fake();
    $this->team->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    $operation = CreateLifecycleOperation::run($this->source, $this->revision, NodeWorkloadAction::REMOVE, $this->user);
    NodeOperation::query()->whereKey($operation->id)->update(['status' => NodeOperationStatus::RUNNING]);

    (new DeleteNodeWorkloadJob($this->workload->id, $this->user->id))->failed(new RuntimeException('Job timed out.'));

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::UNCERTAIN);
    Notification::assertSentTo($this->team, GeneralNotification::class, fn (GeneralNotification $notification) => ! $notification->success);
});

it('does not notify twice when the delete worker fails after it reported the removal failures', function () {
    Notification::fake();
    $this->team->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    Http::fake(fn () => Http::response('Flux rejected the command.', 500));

    $job = new DeleteNodeWorkloadJob($this->workload->id, $this->user->id);
    try {
        $job->handle();
    } catch (RuntimeException $exception) {
        $job->failed($exception);
    }

    expect(isset($exception))->toBeTrue();
    Notification::assertSentToTimes($this->team, GeneralNotification::class, 1);
});

it('gives the delete worker a removal budget for every server', function () {
    $this->target->workloads()->attach($this->workload);
    foreach (range(1, 6) as $index) {
        Node::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->source->private_key_id])
            ->workloads()->attach($this->workload);
    }

    $job = new DeleteNodeWorkloadJob($this->workload->id);

    expect($job->timeout)->toBeGreaterThanOrEqual(8 * (new ManageNodeWorkloadJob(0))->timeout);
});
