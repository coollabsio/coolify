<?php

use App\Actions\Node\VerifyDeploymentConvergence;
use App\Actions\Node\VerifyWorkloadLifecycleConvergence;
use App\Enums\NodeContainerManagementState;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeContainer;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $this->node = Node::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $this->workload = NodeWorkload::factory()->create(['team_id' => $team->id]);
    $this->node->workloads()->attach($this->workload);
    $this->revision = NodeWorkloadRevision::factory()->create([
        'node_workload_id' => $this->workload->id,
        'image' => 'docker.io/library/alpine:latest',
    ]);
});

function proofOperation(object $test, string $commandType, array $request = [], ?array $result = null): NodeOperation
{
    return NodeOperation::factory()->create([
        'node_id' => $test->node->id,
        'node_workload_id' => $test->workload->id,
        'node_workload_revision_id' => $test->revision->id,
        'command_type' => $commandType,
        'status' => NodeOperationStatus::UNCERTAIN,
        'request' => $request,
        'result' => $result,
        'dispatched_at' => now()->subMinute(),
    ]);
}

function proofContainer(object $test, string $state, mixed $startedAt): NodeContainer
{
    return NodeContainer::factory()->create([
        'node_id' => $test->node->id,
        'node_workload_id' => $test->workload->id,
        'node_workload_revision_id' => $test->revision->id,
        'image' => $test->revision->image,
        'state' => $state,
        'management_state' => NodeContainerManagementState::MANAGED,
        'is_managed' => true,
        'runtime_created_at' => $startedAt,
        'runtime_started_at' => $startedAt,
    ]);
}

it('does not confirm a redeploy of the same revision from a container that predates the dispatch', function () {
    proofContainer($this, 'running', now()->subHour());

    $verification = VerifyDeploymentConvergence::run(proofOperation($this, 'workload.deploy.v1'));

    expect($verification['converged'])->toBeFalse();
});

it('confirms a deployment when the container started after the dispatch', function () {
    proofContainer($this, 'running', now());

    expect(VerifyDeploymentConvergence::run(proofOperation($this, 'workload.deploy.v1'))['converged'])->toBeTrue();
});

it('confirms a deployment that Flux acknowledged for the observed container', function () {
    $container = proofContainer($this, 'running', now()->subHour());
    $operation = proofOperation($this, 'workload.deploy.v1');
    $operation->update(['result' => ['command_id' => $operation->uuid, 'runtime_id' => $container->runtime_id]]);

    expect(VerifyDeploymentConvergence::run($operation)['converged'])->toBeTrue();
});

it('does not confirm a restart from a container that predates the dispatch', function () {
    proofContainer($this, 'running', now()->subHour());

    $verification = VerifyWorkloadLifecycleConvergence::run(
        proofOperation($this, 'workload.lifecycle.v1', ['action' => NodeWorkloadAction::RESTART->value]),
    );

    expect($verification['converged'])->toBeFalse();
});

it('confirms a restart when the container started after the dispatch', function () {
    proofContainer($this, 'running', now());

    expect(VerifyWorkloadLifecycleConvergence::run(
        proofOperation($this, 'workload.lifecycle.v1', ['action' => NodeWorkloadAction::RESTART->value]),
    )['converged'])->toBeTrue();
});

it('confirms a restart that Flux acknowledged', function () {
    proofContainer($this, 'running', now()->subHour());
    $operation = proofOperation($this, 'workload.lifecycle.v1', ['action' => NodeWorkloadAction::RESTART->value]);
    $operation->update(['result' => ['command_id' => $operation->uuid]]);

    expect(VerifyWorkloadLifecycleConvergence::run($operation)['converged'])->toBeTrue();
});

it('keeps state based convergence for stop and start', function (NodeWorkloadAction $action, string $state) {
    proofContainer($this, $state, now()->subHour());

    expect(VerifyWorkloadLifecycleConvergence::run(
        proofOperation($this, 'workload.lifecycle.v1', ['action' => $action->value]),
    )['converged'])->toBeTrue();
})->with([
    'start' => [NodeWorkloadAction::START, 'running'],
    'stop' => [NodeWorkloadAction::STOP, 'exited'],
]);
