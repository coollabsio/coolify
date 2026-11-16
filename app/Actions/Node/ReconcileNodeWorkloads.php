<?php

namespace App\Actions\Node;

use App\Enums\NodeContainerSlot;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Enums\NodeWorkloadDesiredState;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Models\Node;
use App\Models\NodeContainer;
use App\Models\NodeOperation;
use App\Models\NodeVolume;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\NodeWorkloadSetEntry;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

class ReconcileNodeWorkloads
{
    use AsAction;

    /** Container states that need no stop. A stop leaves a never-started container in `created`, so stopping it again would loop. */
    private const NOT_RUNNING_STATES = ['exited', 'stopped', 'created', 'configured', 'dead'];

    /** A failed deployment of the same revision on a Node is not retried before this many minutes. */
    private const FAILED_DEPLOYMENT_BACKOFF_MINUTES = 10;

    public function handle(Node $node): int
    {
        $workloads = $node->workloads()
            ->with([
                'revisions' => fn ($query) => $query->latest('id')->limit(1),
                'containers' => fn ($query) => $query
                    ->where('node_id', $node->id)
                    ->where('is_managed', true),
            ])
            ->get();
        $busyWorkloadIds = NodeOperation::query()
            ->where('node_id', $node->id)
            ->whereIn('node_workload_id', $workloads->modelKeys())
            ->whereIn('command_type', ['workload.deploy.v1', 'workload.lifecycle.v1'])
            ->whereIn('status', [
                NodeOperationStatus::QUEUED,
                NodeOperationStatus::DISPATCHED,
                NodeOperationStatus::RUNNING,
                NodeOperationStatus::VERIFYING,
                NodeOperationStatus::UNCERTAIN,
            ])
            ->distinct()
            ->pluck('node_workload_id')
            // A move assigns its target before it deploys there. The move is stored on the source
            // Node, so it blocks the workload on every Node until it finishes.
            // A move of a volume group (decision 0024) blocks every service of the group.
            ->merge(NodeOperation::movingWorkloadIds($workloads->modelKeys()))
            // Sentinel stops the container for a backup with `stop_during_backup` and starts it after.
            ->merge(NodeVolume::query()
                ->whereIn('node_workload_id', $workloads->modelKeys())
                ->whereHas('scheduledBackups', fn ($backups) => $backups
                    ->where('stop_during_backup', true)
                    ->whereHas('executions', fn ($executions) => $executions->where('status', 'running')))
                ->pluck('node_workload_id'));
        $operationCount = 0;
        // Sentinel converges the workloads of its desired set itself (decision 0026).
        $convergesHere = $node->supportsCapability(SyncNodeWorkloadSet::CAPABILITY) === true;
        $inSet = $convergesHere
            ? NodeWorkloadSetEntry::query()->where('node_id', $node->id)->pluck('node_workload_id')
            : collect();
        $adopted = [];

        foreach ($workloads as $workload) {
            if ($inSet->contains($workload->id)) {
                continue;
            }
            // An uncertain operation stays here until the stale operation recovery or a manual Recover closes it.
            if ($busyWorkloadIds->contains($workload->id)) {
                continue;
            }
            if ($convergesHere && $this->adopt($node, $workload)) {
                $adopted[] = $workload->id;

                continue;
            }

            // A service of a stack gets new revisions from staged builds and Compose syncs, which only
            // the stack deploy may roll out, after every build and in `depends_on` order. The
            // reconciler keeps its deployed revision running and does not roll it forward.
            $revision = $workload->node_stack_id === null ? $workload->revisions->first() : $workload->deployedRevision();
            if ($revision === null) {
                continue;
            }

            // One workload that cannot be reconciled (pressure, capability, a racing operation) must not block the rest.
            try {
                $operationCount += $this->reconcileWorkload($node, $workload, $revision);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($adopted !== []) {
            try {
                SyncNodeWorkloadSet::run($node, $adopted);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $operationCount;
    }

    /**
     * Gives a workload of the command path to Sentinel when its deployed revision runs here and
     * Sentinel can converge it (decision 0026). Sentinel adopts the running container; no operation
     * is created.
     */
    private function adopt(Node $node, NodeWorkload $workload): bool
    {
        $revision = $workload->deployedRevision();
        // A newer revision that did not deploy yet stays with the command path until a deploy.
        if ($revision === null || $revision->id !== $workload->revisions->first()?->id
            || $workload->desired_state !== NodeWorkloadDesiredState::RUNNING
            || ! SyncNodeWorkloadSet::converges($node, $workload)) {
            return false;
        }
        $container = $workload->containers
            ->where('node_workload_revision_id', $revision->id)
            ->where('state', 'running')
            ->first();
        $slot = $container === null ? null : collect(NodeContainerSlot::cases())
            ->first(fn (NodeContainerSlot $slot): bool => $slot->containerName($workload) === $container->name);
        if ($slot === null) {
            return false;
        }
        NodeWorkloadSetEntry::query()->firstOrCreate(
            ['node_id' => $node->id, 'node_workload_id' => $workload->id],
            [
                'node_workload_revision_id' => $revision->id,
                'desired_state' => NodeWorkloadDesiredState::RUNNING,
                'container_slot' => $slot,
                'pull_policy' => 'missing',
            ],
        );

        return true;
    }

    private function reconcileWorkload(
        Node $node,
        NodeWorkload $workload,
        NodeWorkloadRevision $latestRevision,
    ): int {
        $containers = $workload->containers;
        $currentContainer = $containers->firstWhere('node_workload_revision_id', $latestRevision->id);
        $container = $currentContainer ?? $containers->first();

        return match ($workload->desired_state) {
            NodeWorkloadDesiredState::RUNNING => $this->reconcileRunning(
                $node,
                $latestRevision,
                $currentContainer,
                $containers->contains('state', 'running'),
            ),
            NodeWorkloadDesiredState::STOPPED => $container !== null
                && ! in_array($container->state, self::NOT_RUNNING_STATES, true)
                    ? $this->dispatchLifecycle($node, $container, $latestRevision, NodeWorkloadAction::STOP)
                    : 0,
            NodeWorkloadDesiredState::REMOVED => $container !== null
                ? $this->dispatchLifecycle($node, $container, $latestRevision, NodeWorkloadAction::REMOVE)
                : 0,
        };
    }

    private function reconcileRunning(
        Node $node,
        NodeWorkloadRevision $latestRevision,
        ?NodeContainer $currentContainer,
        bool $olderRevisionRuns,
    ): int {
        if ($currentContainer?->state === 'running') {
            return 0;
        }
        // A one-time container ran: restart policy `no` means it does not start again (decision 0023).
        if ($currentContainer !== null && data_get($latestRevision->configuration, 'restart_policy') === 'no') {
            return 0;
        }
        if ($currentContainer !== null) {
            // Podman restarts a crashing container itself, and between two restarts the inventory shows it stopped.
            // Without a backoff, Coolify sends a start every minute too.
            if ((int) $currentContainer->restart_count > 0 && $this->recentlyStarted($node, $latestRevision)) {
                return 0;
            }

            return $this->dispatchLifecycle($node, $currentContainer, $latestRevision, NodeWorkloadAction::START);
        }
        // A failed rolling deploy removes its new container and keeps the old one serving. Like v4, it is not retried.
        if ($olderRevisionRuns && NodeOperation::query()
            ->where('node_id', $node->id)
            ->where('node_workload_revision_id', $latestRevision->id)
            ->where('command_type', 'workload.deploy.v1')
            ->where('status', NodeOperationStatus::FAILED)
            ->exists()) {
            return 0;
        }

        // Without a backoff, an image that cannot run creates a failing deployment every minute.
        $recentlyFailed = NodeOperation::query()
            ->where('node_id', $node->id)
            ->where('node_workload_revision_id', $latestRevision->id)
            ->where('command_type', 'workload.deploy.v1')
            ->whereIn('status', [NodeOperationStatus::FAILED, NodeOperationStatus::TIMED_OUT])
            ->where('completed_at', '>=', now()->subMinutes(self::FAILED_DEPLOYMENT_BACKOFF_MINUTES))
            ->exists();
        if ($recentlyFailed) {
            return 0;
        }

        $deployment = CreateDeploymentOperation::run($node, $latestRevision);
        if (! $deployment['created']) {
            return 0;
        }

        DeployNodeWorkloadJob::dispatch($deployment['operation']->id);

        return 1;
    }

    private function recentlyStarted(Node $node, NodeWorkloadRevision $revision): bool
    {
        return NodeOperation::query()
            ->where('node_id', $node->id)
            ->where('node_workload_revision_id', $revision->id)
            ->where('command_type', 'workload.lifecycle.v1')
            ->where('request->action', NodeWorkloadAction::START->value)
            ->where('created_at', '>=', now()->subMinutes(self::FAILED_DEPLOYMENT_BACKOFF_MINUTES))
            ->exists();
    }

    private function dispatchLifecycle(
        Node $node,
        NodeContainer $container,
        NodeWorkloadRevision $fallbackRevision,
        NodeWorkloadAction $action,
    ): int {
        $revision = $container->node_workload_revision_id === null
            ? $fallbackRevision
            : NodeWorkloadRevision::query()->find($container->node_workload_revision_id) ?? $fallbackRevision;
        $operation = CreateLifecycleOperation::run($node, $revision, $action);
        ManageNodeWorkloadJob::dispatch($operation->id);

        return 1;
    }
}
