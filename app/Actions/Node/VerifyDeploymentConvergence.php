<?php

namespace App\Actions\Node;

use App\Models\NodeContainer;
use App\Models\NodeOperation;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class VerifyDeploymentConvergence
{
    use AsAction;

    /**
     * Converged means: the container of this operation runs the revision image and is healthy.
     * Pending means: it runs, but its health check did not pass yet (`starting`). A container
     * without a health check counts as healthy while it runs.
     *
     * @return array{converged: bool, pending: bool, observed_at: mixed, runtime_id: ?string, state: string, image: ?string, health: ?string}
     */
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
        // During a rolling deploy the old container can run the same revision.
        $container = $containers->firstWhere('runtime_id', data_get($operation->result, 'runtime_id'))
            ?? $containers->first(fn ($container): bool => $container->image === $operation->revision->image && $container->state === 'running')
            ?? $containers->first();

        $running = $container !== null
            && $container->image === $operation->revision->image
            && $container->state === 'running'
            && $this->tookEffect($operation, $container);
        $health = $container?->health_status;
        // With a Coolify health check, only `healthy` counts: an inventory without health is not proof.
        $hasHealthCheck = filled(data_get($operation->revision->configuration, 'health_check'));
        $healthy = $hasHealthCheck ? $health === 'healthy' : in_array($health, [null, 'healthy', 'unknown'], true);

        // A one-time container (restart policy `no`) is deployed when it exited with 0 (decision 0023).
        if (data_get($operation->revision->configuration, 'restart_policy') === 'no') {
            $completed = $container !== null
                && $container->image === $operation->revision->image
                && in_array($container->state, ['exited', 'stopped'], true)
                && $container->exit_code === 0
                && $this->tookEffect($operation, $container);

            return [
                'converged' => $completed,
                'pending' => $running,
                'observed_at' => data_get($operation->node->fresh()->metadata, 'container_inventory_observed_at'),
                'runtime_id' => $container?->runtime_id,
                'state' => $container?->state ?? 'missing',
                'image' => $container?->image,
                'health' => $health,
                'exit_code' => $container?->exit_code,
            ];
        }

        return [
            'converged' => $running && $healthy,
            'pending' => $running && ! $healthy && $health !== 'unhealthy',
            'observed_at' => data_get($operation->node->fresh()->metadata, 'container_inventory_observed_at'),
            'runtime_id' => $container?->runtime_id,
            'state' => $container?->state ?? 'missing',
            'image' => $container?->image,
            'health' => $health,
        ];
    }

    /**
     * A redeploy can reuse the running revision, so a running container alone does not prove this command ran.
     * Only the Sentinel acknowledgement for this operation is proof. Container start times come from the Node
     * clock, so they are not compared with Coolify times. Without proof, recovery replays the same operation UUID,
     * and the Sentinel command journal returns the stored result or runs the command once.
     */
    private function tookEffect(NodeOperation $operation, NodeContainer $container): bool
    {
        return data_get($operation->result, 'command_id') === $operation->uuid
            && data_get($operation->result, 'runtime_id') === $container->runtime_id;
    }
}
