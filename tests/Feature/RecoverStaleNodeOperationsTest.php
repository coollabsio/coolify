<?php

use App\Actions\Node\CreateOperation;
use App\Actions\Node\RecoverStaleOperations;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Jobs\RecoverStaleNodeOperationsJob;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'instance_uuid' => 'instance-test']);
    $this->team = Team::factory()->create();
    $key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->cluster = NodeCluster::factory()->create(['team_id' => $this->team->id, 'network_status' => 'pending']);
    $this->node = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $key->id,
        'node_cluster_id' => $this->cluster->id,
    ]);
    $this->workload = NodeWorkload::factory()->create(['team_id' => $this->team->id]);
    $this->node->workloads()->attach($this->workload);
    $this->revision = NodeWorkloadRevision::factory()->create([
        'node_workload_id' => $this->workload->id,
        'image' => 'docker.io/library/alpine:latest',
    ]);
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'test-token');
    config()->set('constants.node.operation_stale_after_minutes', 20);
});

function staleOperation(object $test, string $commandType, NodeOperationStatus $status, array $request = [], int $minutesAgo = 30): NodeOperation
{
    $isWorkload = str_starts_with($commandType, 'workload.');
    $operation = CreateOperation::run(
        $test->node,
        $commandType,
        $commandType.':'.str()->uuid(),
        $isWorkload ? $test->workload : null,
        $isWorkload ? $test->revision : null,
        $request,
    );
    NodeOperation::query()->whereKey($operation->id)->update([
        'status' => $status,
        'updated_at' => now()->subMinutes($minutesAgo),
    ]);

    return $operation->refresh();
}

function fakeInventory(object $test, ?string $state): void
{
    Http::fake(function (Request $request) use ($test, $state) {
        if (! str_ends_with($request->url(), '/v1/commands/container.list')) {
            return Http::response('unexpected command', 500);
        }

        return Http::response([
            'command_id' => 'inventory-1',
            'observed_at_unix_ms' => now()->getTimestampMs(),
            'containers' => $state === null ? [] : [[
                'runtime_id' => 'runtime-1',
                'name' => 'coolify-'.$test->workload->uuid.'-main',
                'image' => $test->revision->image,
                'state' => $state,
                'health_status' => null,
                'restart_count' => 0,
                'ports' => [],
                'labels' => [
                    'coolify.managed' => 'true',
                    'coolify.instance' => 'instance-test',
                    'coolify.workload' => $test->workload->uuid,
                    'coolify.revision' => $test->revision->uuid,
                    'coolify.component' => 'main',
                ],
            ]],
        ]);
    });
}

it('closes stale background reads without sending them again', function () {
    Http::preventStrayRequests();
    $running = staleOperation($this, 'container.list.v1', NodeOperationStatus::RUNNING);
    $queued = staleOperation($this, 'system.info.v1', NodeOperationStatus::QUEUED);

    RecoverStaleOperations::run();

    expect($running->refresh()->status)->toBe(NodeOperationStatus::TIMED_OUT)
        ->and($running->error)->toBe('The operation stopped before completion. The next scheduled run replaces it.')
        ->and($queued->refresh()->status)->toBe(NodeOperationStatus::CANCELLED);
    Http::assertNothingSent();
});

it('does not touch operations that are still within the stale limit', function () {
    Http::preventStrayRequests();
    $operation = staleOperation($this, 'workload.deploy.v1', NodeOperationStatus::RUNNING, minutesAgo: 10);

    RecoverStaleOperations::run();

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::RUNNING);
    Http::assertNothingSent();
});

it('marks a stale deployment as succeeded when the requested revision runs', function () {
    fakeInventory($this, 'running');
    $operation = staleOperation($this, 'workload.deploy.v1', NodeOperationStatus::RUNNING);

    RecoverStaleOperations::run();

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::SUCCEEDED)
        ->and($operation->result['verification']['converged'])->toBeTrue();
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'workload.deploy'));
});

it('closes a stale deployment without replaying it when the revision does not run', function () {
    fakeInventory($this, null);
    $operation = staleOperation($this, 'workload.deploy.v1', NodeOperationStatus::RUNNING);

    RecoverStaleOperations::run();

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::TIMED_OUT)
        ->and($operation->error)->toBe('The operation stopped before completion. The workload reconciler creates a new operation from the current desired state.');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'workload.deploy'));
});

it('verifies a stale uncertain lifecycle operation instead of replaying it', function () {
    fakeInventory($this, 'exited');
    $operation = staleOperation($this, 'workload.lifecycle.v1', NodeOperationStatus::UNCERTAIN, ['revision_uuid' => $this->revision->uuid, 'action' => NodeWorkloadAction::STOP->value]);

    RecoverStaleOperations::run();

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::SUCCEEDED);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'workload.lifecycle'));
});

it('closes a stale workload operation when the Node cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('connection refused'));
    $operation = staleOperation($this, 'workload.lifecycle.v1', NodeOperationStatus::UNCERTAIN, ['revision_uuid' => $this->revision->uuid, 'action' => NodeWorkloadAction::START->value]);

    RecoverStaleOperations::run();

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::TIMED_OUT);
});

it('cancels a stale queued workload operation that was never sent', function () {
    Http::preventStrayRequests();
    $operation = staleOperation($this, 'workload.deploy.v1', NodeOperationStatus::QUEUED);

    RecoverStaleOperations::run();

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::CANCELLED);
    Http::assertNothingSent();
});

it('closes stale network steps and releases a stuck cluster for a manual reconcile', function () {
    Http::preventStrayRequests();
    $operation = staleOperation($this, 'network.firewall.reconcile.v1', NodeOperationStatus::RUNNING);
    NodeCluster::query()->whereKey($this->cluster->id)->update(['network_status' => 'reconciling', 'updated_at' => now()->subMinutes(30)]);

    RecoverStaleOperations::run();

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::TIMED_OUT)
        ->and($operation->error)->toBe('The network step stopped before completion. Reconcile the cluster network again.')
        ->and($this->cluster->refresh()->network_status)->toBe('error');
    Http::assertNothingSent();
});

it('leaves a cluster that is still reconciling within the stale limit', function () {
    $this->cluster->update(['network_status' => 'reconciling']);

    RecoverStaleOperations::run();

    expect($this->cluster->refresh()->network_status)->toBe('reconciling');
});

it('fails stale coordinated operations for a user decision', function (string $commandType, string $message) {
    Http::preventStrayRequests();
    $operation = staleOperation($this, $commandType, NodeOperationStatus::RUNNING);

    RecoverStaleOperations::run();

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::FAILED)
        ->and($operation->error)->toBe($message);
    Http::assertNothingSent();
})->with([
    'move' => ['workload.move.v1', 'The move stopped before completion. Check the workload on both Nodes.'],
    'cluster leave' => ['network.cluster.leave.v1', 'The Node network cleanup stopped before completion. Check the network state on the Node.'],
]);

it('schedules stale operation recovery every five minutes on one scheduler', function () {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event) => str_contains((string) $event->description, RecoverStaleNodeOperationsJob::class),
    );

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->onOneServer)->toBeTrue();
});
