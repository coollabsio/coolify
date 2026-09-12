<?php

namespace App\Actions\Node;

use App\Enums\NodeWorkloadAction;
use App\Models\NodeOperation;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class VerifyWorkloadLifecycleConvergence
{
    use AsAction;

    /** @return array{converged: bool, observed_at: mixed, runtime_id: ?string, state: string, image: ?string} */
    public function handle(NodeOperation $operation): array
    {
        $action = NodeWorkloadAction::tryFrom((string) data_get($operation->request, 'action'));
        if ($operation->node_workload_id === null || $operation->node_workload_revision_id === null || $action === null) {
            throw new RuntimeException('The workload lifecycle operation is incomplete.');
        }

        $operation->loadMissing(['node', 'revision']);
        $containers = $operation->node->containers()
            ->where('node_workload_id', $operation->node_workload_id)
            ->where('is_managed', true)
            ->get();
        $container = $containers->firstWhere('node_workload_revision_id', $operation->node_workload_revision_id)
            ?? $containers->first();
        $converged = match ($action) {
            NodeWorkloadAction::START, NodeWorkloadAction::RESTART => $container !== null
                && $container->node_workload_revision_id === $operation->node_workload_revision_id
                && $container->image === $operation->revision->image
                && $container->state === 'running',
            NodeWorkloadAction::STOP => $container !== null && in_array($container->state, ['exited', 'stopped'], true),
            NodeWorkloadAction::REMOVE => $container === null,
        };

        return [
            'converged' => $converged,
            'observed_at' => data_get($operation->node->fresh()->metadata, 'container_inventory_observed_at'),
            'runtime_id' => $container?->runtime_id,
            'state' => $container?->state ?? 'missing',
            'image' => $container?->image,
        ];
    }
}
