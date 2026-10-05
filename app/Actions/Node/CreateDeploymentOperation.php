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
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class CreateDeploymentOperation
{
    use AsAction;

    /**
     * @param  'missing'|'newer'  $pullPolicy  Use `newer` for user redeploys so mutable tags get the latest image.
     * @return array{operation: NodeOperation, created: bool}
     */
    public function handle(Node $node, NodeWorkloadRevision $revision, ?User $requestedBy = null, string $pullPolicy = 'missing'): array
    {
        $node->ensureCapability('workload.deploy.v1');
        if (! in_array($pullPolicy, ['missing', 'newer'], true)) {
            throw new InvalidArgumentException('The image pull policy is invalid.');
        }

        return DB::transaction(function () use ($node, $revision, $requestedBy, $pullPolicy): array {
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

            if (filled(data_get($revision->configuration, 'resources')) && $node->supportsCapability('workload.resources.v1') !== true) {
                throw new RuntimeException('This server does not support workload resource settings. Upgrade Sentinel and try again.');
            }

            EnsureNodeAcceptsDeployment::run($node->fresh(['cluster']), $revision);
            $workload->update(['desired_state' => NodeWorkloadDesiredState::RUNNING]);

            $operation = CreateOperation::run(
                $node,
                'workload.deploy.v1',
                "deploy:{$node->uuid}:{$revision->uuid}:".Str::uuid(),
                $workload,
                $revision,
                ['revision_uuid' => $revision->uuid, 'configuration_hash' => $revision->configuration_hash, 'pull_policy' => $pullPolicy],
                $requestedBy,
            );

            return ['operation' => $operation, 'created' => true];
        });
    }
}
