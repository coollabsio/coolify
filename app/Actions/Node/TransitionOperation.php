<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\NodeOperation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class TransitionOperation
{
    use AsAction;

    /** @param array<string, mixed>|null $result */
    public function handle(
        NodeOperation $operation,
        NodeOperationStatus $status,
        ?array $result = null,
        ?string $error = null,
    ): NodeOperation {
        return DB::transaction(function () use ($operation, $status, $result, $error): NodeOperation {
            $operation = NodeOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if (! $operation->status->canTransitionTo($status)) {
                throw new InvalidArgumentException("Invalid Node operation transition from {$operation->status->value} to {$status->value}.");
            }

            $attributes = ['status' => $status];
            if ($status === NodeOperationStatus::DISPATCHED) {
                $attributes['attempt_count'] = $operation->attempt_count + 1;
                $attributes['dispatched_at'] = now();
                $attributes['error'] = null;
            }
            if ($status === NodeOperationStatus::RUNNING && $operation->started_at === null) {
                $attributes['started_at'] = now();
            }
            if ($status->isFinal()) {
                $attributes['completed_at'] = now();
            }
            if ($result !== null) {
                $attributes['result'] = $result;
            }
            if ($status === NodeOperationStatus::SUCCEEDED) {
                $attributes['error'] = null;
            }
            if ($error !== null) {
                $attributes['error'] = $error;
            }

            $operation->update($attributes);

            return $operation->refresh();
        });
    }
}
