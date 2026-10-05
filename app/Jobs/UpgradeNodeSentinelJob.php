<?php

namespace App\Jobs;

use App\Actions\Node\TransitionOperation;
use App\Actions\Node\UpgradeSentinel;
use App\Enums\NodeOperationStatus;
use App\Models\NodeOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class UpgradeNodeSentinelJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Install (600 s) + reconnect check (60 s) + rollback (120 s), below the 20 minute stale-operation limit. */
    public int $timeout = 900;

    public function __construct(public int $operationId) {}

    public function handle(): void
    {
        $operation = NodeOperation::query()->with('node')->findOrFail($this->operationId);
        UpgradeSentinel::run($operation);
    }

    public function failed(?Throwable $exception): void
    {
        $operation = NodeOperation::query()->find($this->operationId);
        if ($operation !== null && ! $operation->status->isFinal() && $operation->status->canTransitionTo(NodeOperationStatus::FAILED)) {
            TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: 'The Sentinel upgrade stopped before completion. Check the Sentinel version on the server.');
        }
    }
}
