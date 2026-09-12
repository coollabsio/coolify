<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class CreateLifecycleOperation
{
    use AsAction;

    public function handle(Node $node, NodeWorkloadRevision $revision, NodeWorkloadAction $action, ?User $requestedBy = null): NodeOperation
    {
        return DB::transaction(function () use ($node, $revision, $action, $requestedBy): NodeOperation {
            $workload = NodeWorkload::query()
                ->whereKey($revision->node_workload_id)
                ->where('team_id', $node->team_id)
                ->whereHas('nodes', fn ($nodes) => $nodes->whereKey($node->id))
                ->lockForUpdate()
                ->first();
            if ($workload === null) {
                throw new RuntimeException('The workload is not assigned to this Node.');
            }
            $revision = NodeWorkloadRevision::query()
                ->whereKey($revision->id)
                ->where('node_workload_id', $workload->id)
                ->firstOrFail();
            $active = NodeOperation::query()
                ->where('node_id', $node->id)
                ->where('node_workload_id', $workload->id)
                ->whereIn('status', [
                    NodeOperationStatus::QUEUED,
                    NodeOperationStatus::DISPATCHED,
                    NodeOperationStatus::RUNNING,
                    NodeOperationStatus::VERIFYING,
                    NodeOperationStatus::UNCERTAIN,
                ])
                ->exists();
            if ($active) {
                throw new RuntimeException('This workload already has an active operation.');
            }

            return CreateOperation::run(
                $node,
                'workload.lifecycle.v1',
                "lifecycle:{$node->uuid}:{$workload->uuid}:".Str::uuid(),
                $workload,
                $revision,
                ['revision_uuid' => $revision->uuid, 'action' => $action->value],
                $requestedBy,
            );
        });
    }
}
