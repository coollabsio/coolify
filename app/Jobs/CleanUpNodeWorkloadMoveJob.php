<?php

namespace App\Jobs;

use App\Actions\Node\TransitionOperation;
use App\Enums\NodeOperationStatus;
use App\Models\NodeOperation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rolls back a stateful move whose worker stopped (timeout, killed worker, stale operation),
 * then fails the move. The move stays active until then, so the reconciler waits. When this job
 * stops too, stale operation recovery queues it again; every step is idempotent.
 */
class CleanUpNodeWorkloadMoveJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Inline removal on the target and start on the source; below the stale operation limit. */
    public int $timeout = 900;

    public function __construct(public int $operationId)
    {
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return (string) $this->operationId;
    }

    public function uniqueFor(): int
    {
        return $this->timeout + 60;
    }

    public function handle(): void
    {
        $operation = NodeOperation::query()->with(['node', 'workload', 'revision'])->find($this->operationId);
        if ($operation === null || $operation->status->isFinal()) {
            return;
        }

        MoveNodeWorkloadJob::cleanUp($operation);
        if (! $operation->refresh()->status->isFinal()) {
            TransitionOperation::run(
                $operation,
                NodeOperationStatus::FAILED,
                error: 'The move stopped before completion.'.MoveNodeWorkloadJob::rollbackSummary($operation),
            );
        }
    }
}
