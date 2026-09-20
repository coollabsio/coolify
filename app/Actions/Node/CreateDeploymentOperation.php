<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadDesiredState;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateDeploymentOperation
{
    use AsAction;

    /** @return array{operation: NodeOperation, created: bool} */
    public function handle(Node $node, NodeWorkloadRevision $revision, ?User $requestedBy = null): array
    {
        return DB::transaction(function () use ($node, $revision, $requestedBy): array {
            $workload = NodeWorkload::query()
                ->whereKey($revision->node_workload_id)
                ->where('team_id', $node->team_id)
                ->whereHas('nodes', fn ($nodes) => $nodes->whereKey($node->id))
                ->lockForUpdate()
                ->firstOrFail();
            $revision = NodeWorkloadRevision::query()
                ->whereKey($revision->id)
                ->where('node_workload_id', $workload->id)
                ->firstOrFail();
            $active = NodeOperation::query()
                ->where('node_id', $node->id)
                ->where('node_workload_id', $workload->id)
                ->whereIn('command_type', ['workload.deploy.v1', 'workload.lifecycle.v1'])
                ->whereIn('status', [
                    NodeOperationStatus::QUEUED,
                    NodeOperationStatus::DISPATCHED,
                    NodeOperationStatus::RUNNING,
                    NodeOperationStatus::VERIFYING,
                    NodeOperationStatus::UNCERTAIN,
                ])
                ->latest('id')
                ->first();
            if ($active !== null) {
                return ['operation' => $active, 'created' => false];
            }

            $workload->update(['desired_state' => NodeWorkloadDesiredState::RUNNING]);

            $operation = CreateOperation::run(
                $node,
                'workload.deploy.v1',
                "deploy:{$node->uuid}:{$revision->uuid}:".Str::uuid(),
                $workload,
                $revision,
                ['revision_uuid' => $revision->uuid, 'configuration_hash' => $revision->configuration_hash],
                $requestedBy,
            );

            return ['operation' => $operation, 'created' => true];
        });
    }
}
