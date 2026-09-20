<?php

use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\CreateLifecycleOperation;
use App\Actions\Node\ReconcileNodeWorkloads;
use App\Enums\NodeContainerManagementState;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Enums\NodeWorkloadDesiredState;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Jobs\RefreshNodeContainersJob;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeContainer;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->node = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $key->id,
        'is_usable' => true,
    ]);
    $this->workload = NodeWorkload::factory()->create([
        'team_id' => $this->team->id,
        'desired_state' => NodeWorkloadDesiredState::RUNNING,
    ]);
    $this->node->workloads()->attach($this->workload);
    $this->revision = NodeWorkloadRevision::factory()->create([
        'node_workload_id' => $this->workload->id,
        'image' => 'docker.io/library/alpine:latest',
    ]);
    Queue::fake();
});

it('redeploys a missing workload that should be running', function () {
    ReconcileNodeWorkloads::run($this->node);

    $operation = $this->node->operations()->firstOrFail();
    expect($operation->command_type)->toBe('workload.deploy.v1')
        ->and($operation->node_workload_revision_id)->toBe($this->revision->id);
    Queue::assertPushed(DeployNodeWorkloadJob::class, fn ($job) => $job->operationId === $operation->id);
});

it('reconciles desired state after the scheduled inventory refresh', function () {
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'secret');
    Cache::put($this->node->cacheKey(), [
        'status' => 'connected',
        'last_heartbeat_at' => now()->toIso8601String(),
    ]);
    Http::fake(['*/v1/commands/container.list' => Http::response([
        'command_id' => 'inventory-1',
        'observed_at_unix_ms' => 1_700_000_000_000,
        'containers' => [],
    ])]);

    (new RefreshNodeContainersJob($this->node->id))->handle();

    Queue::assertPushed(DeployNodeWorkloadJob::class);
});

it('starts a stopped current workload that should be running', function () {
    createManagedContainer($this, $this->revision, 'exited');

    ReconcileNodeWorkloads::run($this->node);

    $operation = $this->node->operations()->firstOrFail();
    expect($operation->command_type)->toBe('workload.lifecycle.v1')
        ->and(data_get($operation->request, 'action'))->toBe(NodeWorkloadAction::START->value);
    Queue::assertPushed(ManageNodeWorkloadJob::class, fn ($job) => $job->operationId === $operation->id);
});

it('replaces an outdated running workload with its latest revision', function () {
    $latestRevision = NodeWorkloadRevision::factory()->create([
        'node_workload_id' => $this->workload->id,
        'image' => 'docker.io/library/alpine:3.21',
    ]);
    createManagedContainer($this, $this->revision, 'running');

    ReconcileNodeWorkloads::run($this->node);

    $operation = $this->node->operations()->firstOrFail();
    expect($operation->command_type)->toBe('workload.deploy.v1')
        ->and($operation->node_workload_revision_id)->toBe($latestRevision->id);
    Queue::assertPushed(DeployNodeWorkloadJob::class);
});

it('stops or removes a container to match its desired state', function (NodeWorkloadDesiredState $desiredState, NodeWorkloadAction $action) {
    $this->workload->update(['desired_state' => $desiredState]);
    createManagedContainer($this, $this->revision, 'running');

    ReconcileNodeWorkloads::run($this->node);

    $operation = $this->node->operations()->firstOrFail();
    expect(data_get($operation->request, 'action'))->toBe($action->value);
    Queue::assertPushed(ManageNodeWorkloadJob::class);
})->with([
    'stopped' => [NodeWorkloadDesiredState::STOPPED, NodeWorkloadAction::STOP],
    'removed' => [NodeWorkloadDesiredState::REMOVED, NodeWorkloadAction::REMOVE],
]);

it('does not create work when state matches or another operation is active', function () {
    createManagedContainer($this, $this->revision, 'running');

    ReconcileNodeWorkloads::run($this->node);

    expect($this->node->operations()->count())->toBe(0);
    Queue::assertNothingPushed();

    NodeContainer::query()->delete();
    CreateDeploymentOperation::run($this->node, $this->revision);
    Queue::fake();

    ReconcileNodeWorkloads::run($this->node);

    expect($this->node->operations()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('persists deployment and lifecycle intent for later reconciliation', function () {
    $this->workload->update(['desired_state' => NodeWorkloadDesiredState::REMOVED]);

    CreateDeploymentOperation::run($this->node, $this->revision);

    expect($this->workload->refresh()->desired_state)->toBe(NodeWorkloadDesiredState::RUNNING);
    $this->node->operations()->delete();

    CreateLifecycleOperation::run($this->node, $this->revision, NodeWorkloadAction::STOP);

    expect($this->workload->refresh()->desired_state)->toBe(NodeWorkloadDesiredState::STOPPED);
});

it('retries an uncertain operation instead of creating a duplicate', function () {
    $deployment = CreateDeploymentOperation::run($this->node, $this->revision);
    $deployment['operation']->update(['status' => NodeOperationStatus::UNCERTAIN]);
    Queue::fake();

    ReconcileNodeWorkloads::run($this->node);

    expect($this->node->operations()->count())->toBe(1);
    Queue::assertPushed(DeployNodeWorkloadJob::class, fn ($job) => $job->operationId === $deployment['operation']->id);
});

function createManagedContainer(object $test, NodeWorkloadRevision $revision, string $state): NodeContainer
{
    return NodeContainer::factory()->create([
        'node_id' => $test->node->id,
        'node_workload_id' => $test->workload->id,
        'node_workload_revision_id' => $revision->id,
        'image' => $revision->image,
        'state' => $state,
        'management_state' => NodeContainerManagementState::MANAGED,
        'is_managed' => true,
    ]);
}
