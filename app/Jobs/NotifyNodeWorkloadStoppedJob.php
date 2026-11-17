<?php

namespace App\Jobs;

use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadDesiredState;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadSetEntry;
use App\Notifications\Application\StatusChanged;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends the "resource stopped" notification of v4 NotifyResourceStoppedJob for a cluster
 * application. Sentinel starts a stopped container again by itself, and that restart sends
 * nothing. Only when Sentinel reports that it cannot start the container (status `failed`
 * without an operation) does this job run, after a delay. A container that runs again by then,
 * or an operation of a user that started in the meantime, sends no notification.
 */
class NotifyNodeWorkloadStoppedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $nodeId, public int $workloadId)
    {
        $this->onQueue('high');
        $this->delay(now()->addSeconds(NotifyResourceStoppedJob::CONFIRMATION_DELAY_SECONDS));
    }

    public function handle(): void
    {
        $workload = NodeWorkload::query()->withoutGlobalScopes()->find($this->workloadId);
        if ($workload === null || $workload->desired_state !== NodeWorkloadDesiredState::RUNNING) {
            return;
        }
        $status = NodeWorkloadSetEntry::query()->where('node_id', $this->nodeId)->where('node_workload_id', $workload->id)->value('status');
        $operationRuns = NodeOperation::query()
            ->where('node_id', $this->nodeId)
            ->where('node_workload_id', $workload->id)
            ->whereIn('status', [NodeOperationStatus::QUEUED, NodeOperationStatus::DISPATCHED, NodeOperationStatus::RUNNING, NodeOperationStatus::VERIFYING, NodeOperationStatus::UNCERTAIN])
            ->exists();
        if ($status !== 'failed' || $operationRuns) {
            return;
        }
        // StatusChanged is throttled for each resource, so a container that fails again soon sends once.
        $workload->team()?->notify(new StatusChanged($workload));
    }
}
