<?php

use App\Actions\Node\ClaimOperation;
use App\Enums\NodeOperationStatus;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->node = Node::factory()->create(['team_id' => Team::factory()->create()->id]);
});

function claimableOperation(object $test, NodeOperationStatus $status = NodeOperationStatus::QUEUED): NodeOperation
{
    return NodeOperation::factory()->create(['node_id' => $test->node->id, 'status' => $status]);
}

it('claims an operation that still has the status the caller read', function () {
    $operation = claimableOperation($this);

    $claimed = ClaimOperation::run($operation, NodeOperationStatus::DISPATCHED);

    expect($claimed)->not->toBeNull()
        ->and($claimed->status)->toBe(NodeOperationStatus::DISPATCHED)
        ->and($claimed->attempt_count)->toBe(1)
        ->and($claimed->dispatched_at)->not->toBeNull()
        ->and($operation->refresh()->status)->toBe(NodeOperationStatus::DISPATCHED);
});

it('lets only the first of two workers holding the same snapshot claim the operation', function () {
    $operation = claimableOperation($this);
    $firstWorker = NodeOperation::query()->findOrFail($operation->id);
    $secondWorker = NodeOperation::query()->findOrFail($operation->id);

    $first = ClaimOperation::run($firstWorker, NodeOperationStatus::DISPATCHED);
    $second = ClaimOperation::run($secondWorker, NodeOperationStatus::DISPATCHED);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and($operation->refresh()->attempt_count)->toBe(1);
});

it('does not claim when another worker moved the operation to a status that also allows the transition', function () {
    $operation = claimableOperation($this, NodeOperationStatus::UNCERTAIN);
    $stale = NodeOperation::query()->findOrFail($operation->id);
    $operation->update(['status' => NodeOperationStatus::DISPATCHED]);

    expect(ClaimOperation::run($stale, NodeOperationStatus::FAILED, error: 'stale worker'))->toBeNull()
        ->and($operation->refresh()->status)->toBe(NodeOperationStatus::DISPATCHED)
        ->and($operation->error)->toBeNull();
});

it('returns null for a transition the current status does not allow', function () {
    $operation = claimableOperation($this);

    expect(ClaimOperation::run($operation, NodeOperationStatus::SUCCEEDED))->toBeNull()
        ->and($operation->refresh()->status)->toBe(NodeOperationStatus::QUEUED)
        ->and($operation->completed_at)->toBeNull();
});

it('never claims a final operation', function (NodeOperationStatus $final) {
    $operation = claimableOperation($this, $final);

    foreach (NodeOperationStatus::cases() as $status) {
        expect(ClaimOperation::run($operation, $status))->toBeNull();
    }

    expect($operation->refresh()->status)->toBe($final);
})->with([
    NodeOperationStatus::SUCCEEDED,
    NodeOperationStatus::FAILED,
    NodeOperationStatus::TIMED_OUT,
    NodeOperationStatus::CANCELLED,
]);

it('stores the result of a claimed final transition', function () {
    $operation = claimableOperation($this, NodeOperationStatus::UNCERTAIN);

    $claimed = ClaimOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: ['converged' => true]);

    expect($claimed->status)->toBe(NodeOperationStatus::SUCCEEDED)
        ->and($claimed->result)->toBe(['converged' => true])
        ->and($claimed->completed_at)->not->toBeNull();
});
