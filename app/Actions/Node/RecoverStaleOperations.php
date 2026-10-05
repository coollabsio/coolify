<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\NodeCluster;
use App\Models\NodeOperation;
use Illuminate\Support\Carbon;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Closes Node operations whose worker stopped before completion. It never sends a command again:
 * workload operations are verified against fresh inventory, and everything else waits for the
 * next schedule, the desired-state reconciler, or a user decision.
 */
class RecoverStaleOperations
{
    use AsAction;

    private const ACTIVE_STATUSES = [
        NodeOperationStatus::QUEUED,
        NodeOperationStatus::DISPATCHED,
        NodeOperationStatus::RUNNING,
        NodeOperationStatus::VERIFYING,
        NodeOperationStatus::UNCERTAIN,
    ];

    private const WORKLOAD_COMMANDS = ['workload.deploy.v1', 'workload.lifecycle.v1'];

    private const NETWORK_COMMANDS = [
        'network.wireguard.key.ensure.v1',
        'network.wireguard.reconcile.v1',
        'network.firewall.reconcile.v1',
        'discovery.corrosion.reconcile.v1',
        'ingress.reconcile.v1',
    ];

    private const COORDINATED_ERRORS = [
        'workload.move.v1' => 'The move stopped before completion. Check the workload on both Nodes.',
        'network.cluster.leave.v1' => 'The Node network cleanup stopped before completion. Check the network state on the Node.',
        'sentinel.upgrade.v1' => 'The Sentinel upgrade stopped before completion. Check the Sentinel version on the Node.',
    ];

    /** @var array<int, bool> Whether a fresh inventory is available, by Node ID. */
    private array $inventoryRefreshed = [];

    public function handle(): int
    {
        $staleBefore = now()->subMinutes(config('constants.node.operation_stale_after_minutes', 20));
        $recovered = 0;

        NodeOperation::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('updated_at', '<', $staleBefore)
            ->with(['node', 'revision'])
            ->chunkById(100, function ($operations) use (&$recovered): void {
                foreach ($operations as $operation) {
                    if ($this->recover($operation)) {
                        $recovered++;
                    }
                }
            });
        $this->releaseStuckClusters($staleBefore);

        // An uncertain workload operation has no worker left, for example after a Flux restart.
        // Confirm it before the stale limit when the requested state already runs; otherwise it
        // waits for the stale limit, so a slow command on the Node can still finish.
        NodeOperation::query()
            ->where('status', NodeOperationStatus::UNCERTAIN)
            ->whereIn('command_type', self::WORKLOAD_COMMANDS)
            ->where('updated_at', '>=', $staleBefore)
            ->where('updated_at', '<', now()->subMinute())
            ->with(['node', 'revision'])
            ->chunkById(100, function ($operations) use (&$recovered): void {
                foreach ($operations as $operation) {
                    if ($this->confirmConverged($operation)) {
                        $recovered++;
                    }
                }
            });

        return $recovered;
    }

    private function confirmConverged(NodeOperation $operation): bool
    {
        $verification = $this->verify($operation);
        if (! ($verification['converged'] ?? false)) {
            return false;
        }

        return ClaimOperation::run(
            $operation,
            NodeOperationStatus::SUCCEEDED,
            result: [...($operation->result ?? []), 'verification' => $verification],
        ) !== null;
    }

    private function recover(NodeOperation $operation): bool
    {
        return match (true) {
            in_array($operation->command_type, NodeOperation::BACKGROUND_COMMAND_TYPES, true) => $this->close($operation, 'The operation stopped before completion. The next scheduled run replaces it.'),
            in_array($operation->command_type, self::NETWORK_COMMANDS, true) => $this->close($operation, 'The network step stopped before completion. Reconcile the cluster network again.'),
            in_array($operation->command_type, self::WORKLOAD_COMMANDS, true) => $this->recoverWorkload($operation),
            default => ClaimOperation::run(
                $operation,
                NodeOperationStatus::FAILED,
                error: self::COORDINATED_ERRORS[$operation->command_type] ?? 'The operation stopped before completion.',
            ) !== null,
        };
    }

    private function recoverWorkload(NodeOperation $operation): bool
    {
        $error = 'The operation stopped before completion. The workload reconciler creates a new operation from the current desired state.';
        if ($operation->status === NodeOperationStatus::QUEUED) {
            return $this->close($operation, $error);
        }

        $verification = $this->verify($operation);
        if (! ($verification['converged'] ?? false)) {
            return $this->close($operation, $error);
        }

        $result = [...($operation->result ?? []), 'verification' => $verification];
        $claimed = ClaimOperation::run(
            $operation,
            $operation->status === NodeOperationStatus::RUNNING ? NodeOperationStatus::VERIFYING : NodeOperationStatus::SUCCEEDED,
            result: $result,
        );
        if ($claimed?->status === NodeOperationStatus::VERIFYING) {
            TransitionOperation::run($claimed, NodeOperationStatus::SUCCEEDED, result: $result);
        }

        return $claimed !== null;
    }

    /** @return array<string, mixed>|null */
    private function verify(NodeOperation $operation): ?array
    {
        if (! array_key_exists($operation->node_id, $this->inventoryRefreshed)) {
            try {
                FetchContainers::run($operation->node);
                $this->inventoryRefreshed[$operation->node_id] = true;
            } catch (Throwable) {
                $this->inventoryRefreshed[$operation->node_id] = false;
            }
        }
        if (! $this->inventoryRefreshed[$operation->node_id]) {
            return null;
        }

        try {
            return $operation->command_type === 'workload.deploy.v1'
                ? VerifyDeploymentConvergence::run($operation)
                : VerifyWorkloadLifecycleConvergence::run($operation);
        } catch (Throwable) {
            return null;
        }
    }

    private function close(NodeOperation $operation, string $error): bool
    {
        $status = $operation->status === NodeOperationStatus::QUEUED
            ? NodeOperationStatus::CANCELLED
            : NodeOperationStatus::TIMED_OUT;

        return ClaimOperation::run($operation, $status, error: $error) !== null;
    }

    /** A reconcile job sets `reconciling` and always finishes within the stale limit, so an older state means the job stopped. */
    private function releaseStuckClusters(Carbon $staleBefore): void
    {
        NodeCluster::query()
            ->where('network_status', 'reconciling')
            ->where('updated_at', '<', $staleBefore)
            ->whereDoesntHave('nodes.operations', fn ($query) => $query
                ->whereIn('command_type', self::NETWORK_COMMANDS)
                ->whereIn('status', self::ACTIVE_STATUSES))
            ->update(['network_status' => 'error']);
    }
}
