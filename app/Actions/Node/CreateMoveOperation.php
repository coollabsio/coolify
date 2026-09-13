<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class CreateMoveOperation
{
    use AsAction;

    public function handle(Node $source, Node $target, NodeWorkloadRevision $revision, ?User $requestedBy = null): NodeOperation
    {
        $source->refresh();
        $target->refresh();

        return DB::transaction(function () use ($source, $target, $revision, $requestedBy): NodeOperation {
            if ($source->id === $target->id
                || $source->team_id !== $target->team_id
                || $source->node_cluster_id === null
                || $source->node_cluster_id !== $target->node_cluster_id) {
                throw new RuntimeException('Select another Node in the same mesh.');
            }

            $workload = NodeWorkload::query()
                ->whereKey($revision->node_workload_id)
                ->where('team_id', $source->team_id)
                ->whereHas('nodes', fn ($nodes) => $nodes->whereKey($source->id))
                ->lockForUpdate()
                ->firstOrFail();
            $revision = NodeWorkloadRevision::query()
                ->whereKey($revision->id)
                ->where('node_workload_id', $workload->id)
                ->firstOrFail();
            $hasActiveMove = NodeOperation::query()
                ->where('node_workload_id', $workload->id)
                ->where('command_type', 'workload.move.v1')
                ->whereIn('status', [
                    NodeOperationStatus::QUEUED,
                    NodeOperationStatus::DISPATCHED,
                    NodeOperationStatus::RUNNING,
                    NodeOperationStatus::VERIFYING,
                    NodeOperationStatus::UNCERTAIN,
                ])
                ->exists();
            if ($hasActiveMove) {
                throw new RuntimeException('This workload already has an active move.');
            }

            return CreateOperation::run(
                $source,
                'workload.move.v1',
                "move:{$source->uuid}:{$target->uuid}:{$workload->uuid}:".Str::uuid(),
                $workload,
                $revision,
                ['revision_uuid' => $revision->uuid, 'target_node_uuid' => $target->uuid],
                $requestedBy,
            );
        });
    }
}
