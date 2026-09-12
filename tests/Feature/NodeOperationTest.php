<?php

use App\Actions\Node\CleanupOperations;
use App\Actions\Node\CreateOperation;
use App\Actions\Node\TransitionOperation;
use App\Enums\NodeOperationStatus;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->node = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->key->id,
    ]);
    $this->workload = NodeWorkload::factory()->create(['team_id' => $this->team->id]);
    $this->revision = NodeWorkloadRevision::factory()->create([
        'node_workload_id' => $this->workload->id,
    ]);
});

it('creates one durable operation for an idempotency key', function () {
    $first = CreateOperation::run(
        node: $this->node,
        commandType: 'workload.deploy.v1',
        idempotencyKey: 'deploy-42',
        workload: $this->workload,
        revision: $this->revision,
        request: ['image' => 'alpine:latest'],
    );
    $second = CreateOperation::run(
        node: $this->node,
        commandType: 'workload.deploy.v1',
        idempotencyKey: 'deploy-42',
        workload: $this->workload,
        revision: $this->revision,
        request: ['image' => 'alpine:latest'],
    );

    expect($second->is($first))->toBeTrue()
        ->and(NodeOperation::query()->count())->toBe(1)
        ->and($first->status)->toBe(NodeOperationStatus::QUEUED)
        ->and($first->node->is($this->node))->toBeTrue()
        ->and($first->workload->is($this->workload))->toBeTrue()
        ->and($first->revision->is($this->revision))->toBeTrue();
});

it('rejects reuse of an idempotency key for another request', function () {
    CreateOperation::run($this->node, 'workload.deploy.v1', 'same-key', request: ['image' => 'one']);

    expect(fn () => CreateOperation::run(
        $this->node,
        'workload.deploy.v1',
        'same-key',
        request: ['image' => 'two'],
    ))->toThrow(InvalidArgumentException::class, 'Idempotency key');
});

it('records valid operation transitions and attempts', function () {
    $operation = NodeOperation::factory()->create([
        'node_id' => $this->node->id,
        'status' => NodeOperationStatus::QUEUED,
    ]);

    TransitionOperation::run($operation, NodeOperationStatus::DISPATCHED);
    TransitionOperation::run($operation, NodeOperationStatus::RUNNING);
    TransitionOperation::run($operation, NodeOperationStatus::VERIFYING, result: ['container' => 'web']);
    TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: ['container' => 'web']);

    $operation->refresh();
    expect($operation->status)->toBe(NodeOperationStatus::SUCCEEDED)
        ->and($operation->attempt_count)->toBe(1)
        ->and($operation->dispatched_at)->not->toBeNull()
        ->and($operation->started_at)->not->toBeNull()
        ->and($operation->completed_at)->not->toBeNull()
        ->and($operation->result)->toBe(['container' => 'web']);
});

it('rejects invalid operation transitions', function () {
    $operation = NodeOperation::factory()->create([
        'node_id' => $this->node->id,
        'status' => NodeOperationStatus::SUCCEEDED,
        'completed_at' => now(),
    ]);

    expect(fn () => TransitionOperation::run($operation, NodeOperationStatus::RUNNING))
        ->toThrow(InvalidArgumentException::class, 'transition');
});

it('can fail a queued operation before dispatch', function () {
    $operation = NodeOperation::factory()->create([
        'node_id' => $this->node->id,
        'status' => NodeOperationStatus::QUEUED,
    ]);

    TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: 'Could not dispatch.');

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::FAILED)
        ->and($operation->error)->toBe('Could not dispatch.')
        ->and($operation->completed_at)->not->toBeNull();
});

it('cleans final operations by outcome retention and keeps active operations', function () {
    NodeOperation::factory()->create([
        'node_id' => $this->node->id,
        'status' => NodeOperationStatus::SUCCEEDED,
        'completed_at' => now()->subDays(31),
    ]);
    NodeOperation::factory()->create([
        'node_id' => $this->node->id,
        'status' => NodeOperationStatus::FAILED,
        'completed_at' => now()->subDays(91),
    ]);
    $recent = NodeOperation::factory()->create([
        'node_id' => $this->node->id,
        'status' => NodeOperationStatus::SUCCEEDED,
        'completed_at' => now()->subDays(29),
    ]);
    $active = NodeOperation::factory()->create([
        'node_id' => $this->node->id,
        'status' => NodeOperationStatus::RUNNING,
        'created_at' => now()->subYear(),
    ]);

    expect(CleanupOperations::run())->toBe(2)
        ->and(NodeOperation::query()->pluck('id')->all())->toContain($recent->id, $active->id);
});

it('limits operation access to the Node team', function () {
    $owner = User::factory()->create();
    $owner->teams()->attach($this->team, ['role' => 'owner']);
    $outsider = User::factory()->create();
    $operation = NodeOperation::factory()->create(['node_id' => $this->node->id]);

    expect($owner->can('view', $operation))->toBeTrue()
        ->and($owner->can('update', $operation))->toBeTrue()
        ->and($outsider->can('view', $operation))->toBeFalse()
        ->and($outsider->can('update', $operation))->toBeFalse();
});

it('clears an uncertain error when recovery succeeds', function () {
    $operation = NodeOperation::factory()->create([
        'node_id' => $this->node->id,
        'status' => NodeOperationStatus::UNCERTAIN,
        'error' => 'The deployment result is unknown.',
    ]);

    TransitionOperation::run($operation, NodeOperationStatus::DISPATCHED);
    TransitionOperation::run($operation, NodeOperationStatus::RUNNING);
    TransitionOperation::run($operation, NodeOperationStatus::VERIFYING, result: ['runtime_id' => 'container-1']);
    TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: ['runtime_id' => 'container-1']);

    expect($operation->refresh()->error)->toBeNull();
});
