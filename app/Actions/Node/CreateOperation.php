<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateOperation
{
    use AsAction;

    /** @param array<string, mixed> $request */
    public function handle(
        Node $node,
        string $commandType,
        string $idempotencyKey,
        ?NodeWorkload $workload = null,
        ?NodeWorkloadRevision $revision = null,
        array $request = [],
        ?User $requestedBy = null,
    ): NodeOperation {
        if (blank($commandType) || mb_strlen($commandType) > 100) {
            throw new InvalidArgumentException('The command type is invalid.');
        }
        if (blank($idempotencyKey) || mb_strlen($idempotencyKey) > 255) {
            throw new InvalidArgumentException('The idempotency key is invalid.');
        }
        if ($workload !== null && $workload->team_id !== $node->team_id) {
            throw new InvalidArgumentException('The workload and Node must belong to the same team.');
        }
        if ($revision !== null && ($workload === null || $revision->node_workload_id !== $workload->id)) {
            throw new InvalidArgumentException('The revision must belong to the operation workload.');
        }

        return DB::transaction(function () use ($node, $commandType, $idempotencyKey, $workload, $revision, $request, $requestedBy): NodeOperation {
            $operation = NodeOperation::query()->firstOrCreate(
                ['node_id' => $node->id, 'idempotency_key' => $idempotencyKey],
                [
                    'node_workload_id' => $workload?->id,
                    'node_workload_revision_id' => $revision?->id,
                    'requested_by_id' => $requestedBy?->id,
                    'command_type' => $commandType,
                    'status' => NodeOperationStatus::QUEUED,
                    'request' => $request,
                ],
            );

            if (! $operation->wasRecentlyCreated && (
                $operation->command_type !== $commandType
                || $operation->node_workload_id !== $workload?->id
                || $operation->node_workload_revision_id !== $revision?->id
                || $operation->request !== $request
            )) {
                throw new InvalidArgumentException('Idempotency key was already used for another operation.');
            }

            return $operation;
        });
    }
}
