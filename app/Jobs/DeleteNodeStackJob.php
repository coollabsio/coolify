<?php

namespace App\Jobs;

use App\Models\NodeStack;
use App\Models\NodeWorkload;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Deletes a stack: each service through the deletion of a cluster application, then the stack.
 * A service that cannot be deleted stays, with the notification of DeleteNodeWorkloadJob, and
 * keeps the stack, so a retry deletes the rest.
 */
class DeleteNodeStackJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public int $stackId,
        public ?int $requestedById = null,
        public bool $deleteVolumes = true,
    ) {
        $this->onQueue('high');
        $budget = NodeWorkload::query()->where('node_stack_id', $stackId)->pluck('id')
            ->sum(fn (int $workloadId): int => (new DeleteNodeWorkloadJob($workloadId))->timeout);
        $this->timeout = max($this->timeout, $budget + 120);
    }

    public function uniqueId(): string
    {
        return (string) $this->stackId;
    }

    public function uniqueFor(): int
    {
        return $this->timeout + 60;
    }

    public function handle(): void
    {
        $stack = NodeStack::query()->withoutGlobalScopes()->find($this->stackId);
        if ($stack === null) {
            return;
        }
        foreach ($stack->workloads()->withoutGlobalScopes()->pluck('id') as $workloadId) {
            try {
                (new DeleteNodeWorkloadJob($workloadId, $this->requestedById, deleteVolumes: $this->deleteVolumes))->handle();
            } catch (Throwable $exception) {
                // DeleteNodeWorkloadJob notified the team. The other services are still deleted.
                report($exception);
            }
        }
        if (! NodeWorkload::query()->withoutGlobalScopes()->where('node_stack_id', $stack->id)->exists()) {
            $stack->delete();
        }
    }
}
