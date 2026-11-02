<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\NodeOperation;
use Lorisleiva\Actions\Concerns\AsAction;

class CleanupOperations
{
    use AsAction;

    public function handle(): int
    {
        // Periodic checks run every few minutes for every Node, so their successful rows are kept only briefly.
        $background = NodeOperation::query()
            ->where('status', NodeOperationStatus::SUCCEEDED)
            ->whereIn('command_type', NodeOperation::BACKGROUND_COMMAND_TYPES)
            ->where('completed_at', '<', now()->subDay())
            ->delete();
        $successful = NodeOperation::query()
            ->where('status', NodeOperationStatus::SUCCEEDED)
            ->where('completed_at', '<', now()->subDays(config('constants.node.operation_success_retention_days', 30)))
            ->delete();
        $failed = NodeOperation::query()
            ->whereIn('status', [NodeOperationStatus::FAILED, NodeOperationStatus::TIMED_OUT, NodeOperationStatus::CANCELLED])
            ->where('completed_at', '<', now()->subDays(config('constants.node.operation_failure_retention_days', 90)))
            ->delete();

        return $background + $successful + $failed;
    }
}
