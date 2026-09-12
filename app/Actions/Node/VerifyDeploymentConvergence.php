<?php

namespace App\Actions\Node;

use App\Models\NodeOperation;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class VerifyDeploymentConvergence
{
    use AsAction;

    /** @return array{converged: bool, observed_at: mixed, runtime_id: ?string, state: string, image: ?string} */
    public function handle(NodeOperation $operation): array
    {
        if ($operation->node_workload_id === null || $operation->node_workload_revision_id === null) {
            throw new RuntimeException('The deployment operation has no workload revision.');
        }

        $operation->loadMissing(['node', 'revision']);
        $containers = $operation->node->containers()
            ->where('node_workload_id', $operation->node_workload_id)
            ->where('node_workload_revision_id', $operation->node_workload_revision_id)
            ->where('is_managed', true)
            ->get();
        $container = $containers->first(fn ($container): bool => $container->image === $operation->revision->image && $container->state === 'running')
            ?? $containers->first();

        return [
            'converged' => $container !== null
                && $container->image === $operation->revision->image
                && $container->state === 'running',
            'observed_at' => data_get($operation->node->fresh()->metadata, 'container_inventory_observed_at'),
            'runtime_id' => $container?->runtime_id,
            'state' => $container?->state ?? 'missing',
            'image' => $container?->image,
        ];
    }
}
