<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\Node;
use App\Models\NodeOperation;
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
            $revision = NodeWorkloadRevision::query()
                ->with('workload')
                ->lockForUpdate()
                ->findOrFail($revision->id);
            $active = NodeOperation::query()
                ->where('node_id', $node->id)
                ->where('node_workload_revision_id', $revision->id)
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

            $operation = CreateOperation::run(
                $node,
                'workload.deploy.v1',
                "deploy:{$node->uuid}:{$revision->uuid}:".Str::uuid(),
                $revision->workload,
                $revision,
                ['revision_uuid' => $revision->uuid, 'configuration_hash' => $revision->configuration_hash],
                $requestedBy,
            );

            return ['operation' => $operation, 'created' => true];
        });
    }
}
