<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\NodeWorkloadRevision;
use App\Models\User;
use App\Support\ContainerIsolation;
use App\Support\NodeRuntimeOptions;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Checks the runtime options of decision 0032 before a deploy, a rollback, a build deploy, or a
 * move creates its operation: the team-admin tier of `--cap-add` and `--sysctl`, the Sentinel
 * capability, and a GPU on the Node.
 */
class EnsureNodeRuntimeOptions
{
    use AsAction;

    /** @throws RuntimeException With the reason. */
    public function handle(Node $node, NodeWorkloadRevision $revision, ?User $requestedBy, int $teamId): void
    {
        $configuration = $revision->configuration ?? [];
        $runtimeOptions = (array) ($configuration['runtime_options'] ?? []);
        // Every deploy of a user: a new or the same revision, a rollback, a build, and a move.
        $service = $revision->workload?->stack_service;
        ContainerIsolation::ensureMayUseAdminTier($requestedBy, $teamId, $runtimeOptions, $service !== null ? "The service {$service}" : 'This application');
        if (filled($configuration['user_labels'] ?? null) || array_intersect_key($runtimeOptions, array_flip(NodeRuntimeOptions::V2_KEYS)) !== []) {
            $node->ensureReportedCapability(DispatchWorkloadDeployment::RUNTIME_OPTIONS_V2_CAPABILITY);
        }
        DispatchWorkloadDeployment::cdiDevices($node, (array) ($configuration['gpu'] ?? []));
    }
}
