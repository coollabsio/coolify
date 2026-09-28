<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\NodeOperation;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Transitions an operation only when it still has the status that the caller read.
 * Returns null when another worker changed the operation first, so the caller must stop.
 */
class ClaimOperation
{
    use AsAction;

    /** @param array<string, mixed>|null $result */
    public function handle(NodeOperation $operation, NodeOperationStatus $status, ?array $result = null, ?string $error = null): ?NodeOperation
    {
        return DB::transaction(function () use ($operation, $status, $result, $error): ?NodeOperation {
            $current = NodeOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($current->status !== $operation->status || ! $current->status->canTransitionTo($status)) {
                return null;
            }

            return TransitionOperation::run($current, $status, $result, $error);
        });
    }
}
