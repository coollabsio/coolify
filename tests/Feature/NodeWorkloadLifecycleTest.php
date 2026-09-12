<?php

use App\Actions\Node\CreateLifecycleOperation;
use App\Enums\NodeContainerManagementState;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Jobs\ManageNodeWorkloadJob;
use App\Livewire\Node\Show;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeContainer;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'instance_uuid' => 'instance-test']);
    $this->team = Team::factory()->create();
    $this->key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->node = Node::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->key->id]);
    $this->workload = NodeWorkload::factory()->create(['team_id' => $this->team->id, 'name' => 'Example App']);
    $this->node->workloads()->attach($this->workload);
    $this->revision = NodeWorkloadRevision::factory()->create([
        'node_workload_id' => $this->workload->id,
        'image' => 'docker.io/library/alpine:latest',
    ]);
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'test-token');
});

it('converges each workload lifecycle command', function (NodeWorkloadAction $action, array $containers) {
    $operation = CreateLifecycleOperation::run($this->node, $this->revision, $action);
    Http::fake(function ($request) use ($action, $containers, $operation) {
        if (str_ends_with($request->url(), '/v1/commands/workload.lifecycle')) {
            expect($request['command_id'])->toBe($operation->uuid)
                ->and($request['name'])->toBe('coolify-'.$this->workload->uuid.'-main')
                ->and($request['action'])->toBe($action->value);

            return Http::response([
                'command_id' => $operation->uuid,
                'observed_at_unix_ms' => 1_700_000_000_000,
                'name' => 'coolify-'.$this->workload->uuid.'-main',
                'action' => $action->value,
            ]);
        }

        return Http::response([
            'command_id' => 'inventory-1',
            'observed_at_unix_ms' => 1_700_000_000_100,
            'containers' => $containers,
        ]);
    });

    (new ManageNodeWorkloadJob($operation->id))->handle();

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::SUCCEEDED)
        ->and($operation->result['verification']['converged'])->toBeTrue()
        ->and($operation->result['action'])->toBe($action->value);
})->with([
    'start' => fn () => [NodeWorkloadAction::START, [lifecycleContainer($this, 'running')]],
    'stop' => fn () => [NodeWorkloadAction::STOP, [lifecycleContainer($this, 'exited')]],
    'restart' => fn () => [NodeWorkloadAction::RESTART, [lifecycleContainer($this, 'running')]],
    'remove' => fn () => [NodeWorkloadAction::REMOVE, []],
]);

it('waits for a transitional container state to converge', function () {
    Sleep::fake();
    $operation = CreateLifecycleOperation::run($this->node, $this->revision, NodeWorkloadAction::STOP);
    $inventoryCalls = 0;
    Http::fake(function ($request) use ($operation, &$inventoryCalls) {
        if (str_ends_with($request->url(), '/v1/commands/workload.lifecycle')) {
            return Http::response([
                'command_id' => $operation->uuid,
                'observed_at_unix_ms' => 1_700_000_000_000,
                'name' => 'coolify-'.$this->workload->uuid.'-main',
                'action' => 'stop',
            ]);
        }

        $inventoryCalls++;

        return Http::response([
            'command_id' => 'inventory-'.$inventoryCalls,
            'observed_at_unix_ms' => 1_700_000_000_100 + $inventoryCalls,
            'containers' => [lifecycleContainer($this, $inventoryCalls === 1 ? 'stopping' : 'exited')],
        ]);
    });

    (new ManageNodeWorkloadJob($operation->id))->handle();

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::SUCCEEDED)
        ->and($inventoryCalls)->toBe(2)
        ->and($operation->result['verification']['state'])->toBe('exited');
    Sleep::assertSleptTimes(1);
});

it('fails when the requested lifecycle state is not reached', function () {
    Sleep::fake();
    $operation = CreateLifecycleOperation::run($this->node, $this->revision, NodeWorkloadAction::START);
    Http::fake(function ($request) use ($operation) {
        if (str_ends_with($request->url(), '/v1/commands/workload.lifecycle')) {
            return Http::response([
                'command_id' => $operation->uuid,
                'observed_at_unix_ms' => 1_700_000_000_000,
                'name' => 'coolify-'.$this->workload->uuid.'-main',
                'action' => 'start',
            ]);
        }

        return Http::response([
            'command_id' => 'inventory-1',
            'observed_at_unix_ms' => 1_700_000_000_100,
            'containers' => [],
        ]);
    });

    (new ManageNodeWorkloadJob($operation->id))->handle();

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::FAILED)
        ->and($operation->result['verification']['converged'])->toBeFalse()
        ->and($operation->error)->toBe('The requested workload state was not reached.');
});

it('recovers from observed lifecycle state without replaying the command', function () {
    $operation = CreateLifecycleOperation::run($this->node, $this->revision, NodeWorkloadAction::STOP);
    $operation->update(['status' => NodeOperationStatus::UNCERTAIN, 'error' => 'Unknown result.']);
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/v1/commands/workload.lifecycle')) {
            return Http::response('Must not replay', 500);
        }

        return Http::response([
            'command_id' => 'inventory-1',
            'observed_at_unix_ms' => 1_700_000_000_100,
            'containers' => [lifecycleContainer($this, 'exited')],
        ]);
    });

    (new ManageNodeWorkloadJob($operation->id))->handle();

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::SUCCEEDED)
        ->and($operation->attempt_count)->toBe(0);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v1/commands/workload.lifecycle'));
});

it('queues an authorized lifecycle action from the Node page', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    $user = User::factory()->create();
    $user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);
    Queue::fake();
    $this->node->update(['metadata' => ['container_inventory_observed_at' => now()->toIso8601String()]]);
    NodeContainer::factory()->create([
        'node_id' => $this->node->id,
        'node_workload_id' => $this->workload->id,
        'node_workload_revision_id' => $this->revision->id,
        'image' => $this->revision->image,
        'state' => 'running',
        'is_managed' => true,
        'management_state' => NodeContainerManagementState::MANAGED,
    ]);

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->assertSee('Stop')
        ->assertSee('Restart')
        ->assertSee('Remove')
        ->call('manageWorkload', 'restart', $this->revision->uuid)
        ->assertDispatched('success');

    $operation = $this->node->operations()->firstOrFail();
    expect($operation->request)->toBe([
        'revision_uuid' => $this->revision->uuid,
        'action' => 'restart',
    ]);
    Queue::assertPushed(ManageNodeWorkloadJob::class, fn ($job) => $job->operationId === $operation->id);
});

it('queues lifecycle recovery from the Node page', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    $user = User::factory()->create();
    $user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);
    $operation = CreateLifecycleOperation::run($this->node, $this->revision, NodeWorkloadAction::STOP);
    $operation->update(['status' => NodeOperationStatus::UNCERTAIN]);
    Queue::fake();

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->call('retryOperation', $operation->uuid)
        ->assertDispatched('success');

    Queue::assertPushed(ManageNodeWorkloadJob::class, fn ($job) => $job->operationId === $operation->id);
});

it('cannot manage a workload that is not assigned to the Node', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    $unassigned = NodeWorkload::factory()->create(['team_id' => $this->team->id]);
    $revision = NodeWorkloadRevision::factory()->create(['node_workload_id' => $unassigned->id]);
    $user = User::factory()->create();
    $user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);
    Queue::fake();

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->call('manageWorkload', 'stop', $revision->uuid);

    expect($this->node->operations()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects invalid lifecycle actions', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    $user = User::factory()->create();
    $user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);
    Queue::fake();

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->call('manageWorkload', 'destroy-host', $this->revision->uuid);

    expect($this->node->operations()->count())->toBe(0);
    Queue::assertNothingPushed();
});

function lifecycleContainer(object $test, string $state): array
{
    return [
        'runtime_id' => 'runtime-123',
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
    ];
}
