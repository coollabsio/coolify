<?php

namespace App\Jobs;

use App\Actions\Node\ClaimOperation;
use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\CreateLifecycleOperation;
use App\Actions\Node\EnsureNodeWorkloadAddress;
use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Actions\Node\TransitionOperation;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeOperation;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Moves a workload to another Node of its cluster: make before break. A workload with ingress
 * routes first gets the firewall allow for its target address on every reachable Node, and its
 * source keeps serving until ingress learned the target.
 */
class MoveNodeWorkloadJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** How long the reachable Nodes may take to apply the network revision that allows the target. */
    public const NETWORK_WAIT_SECONDS = 120;

    /**
     * How long the source keeps serving after the target became ready. The target Sentinel
     * publishes the endpoint to Corrosion at once, Corrosion replicates it to the other Nodes
     * within about a second, and each ingress renderer reads it on its next pass (every 5 s).
     */
    public const INGRESS_SETTLE_SECONDS = 10;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $operationId) {}

    public function handle(): void
    {
        $operation = NodeOperation::query()->with(['node', 'workload', 'revision'])->findOrFail($this->operationId);
        if ($operation->status->isFinal()) {
            return;
        }

        $targetWasAssigned = true;
        $targetDeploymentSucceeded = false;
        $networkRevisionQueued = false;
        try {
            $target = Node::query()
                ->where('uuid', data_get($operation->request, 'target_node_uuid'))
                ->where('team_id', $operation->node->team_id)
                ->where('node_cluster_id', $operation->node->node_cluster_id)
                ->firstOrFail();
            if (ClaimOperation::run($operation, NodeOperationStatus::DISPATCHED) === null) {
                return;
            }
            $operation = TransitionOperation::run($operation, NodeOperationStatus::RUNNING);

            $targetWasAssigned = $target->workloads()->whereKey($operation->workload->id)->exists();
            if (! $targetWasAssigned) {
                $target->workloads()->attach($operation->workload);
            }
            $cluster = $target->cluster;
            $routed = $operation->workload->hasIngressRoutes() && $cluster !== null;
            if ($routed) {
                // Every ingress Node must reach the target address before the target serves traffic.
                // DispatchWorkloadDeployment then finds the address and queues no second revision.
                EnsureNodeWorkloadAddress::run($target, $operation->workload);
                $networkRevisionQueued = true;
                $this->waitForNetworkRevision($cluster, $operation->requestedBy);
            }

            $deployment = CreateDeploymentOperation::run($target, $operation->revision, $operation->requestedBy);
            if ($deployment['created']) {
                (new DeployNodeWorkloadJob($deployment['operation']->id))->handle();
            }
            $deploymentOperation = $deployment['operation']->refresh();
            if ($deploymentOperation->status !== NodeOperationStatus::SUCCEEDED) {
                throw new RuntimeException('The workload did not become ready on the target Node. '.($deploymentOperation->error ?? ''));
            }
            $targetDeploymentSucceeded = true;
            $targetContainer = $target->containers()
                ->where('node_workload_id', $operation->workload->id)
                ->where('node_workload_revision_id', $operation->revision->id)
                ->where('is_managed', true)
                ->where('state', 'running')
                ->first();
            if ($targetContainer === null || ! in_array($targetContainer->health_status, [null, 'healthy', 'unknown'], true)) {
                throw new RuntimeException('The target workload is not healthy. The source remains active.');
            }

            if ($routed) {
                Sleep::for(self::INGRESS_SETTLE_SECONDS)->seconds();
            }

            $removalOperation = CreateLifecycleOperation::run(
                $operation->node,
                $operation->revision,
                NodeWorkloadAction::REMOVE,
                $operation->requestedBy,
                updateDesiredState: false,
            );
            (new ManageNodeWorkloadJob($removalOperation->id))->handle();
            $removalOperation->refresh();
            if ($removalOperation->status !== NodeOperationStatus::SUCCEEDED) {
                throw new RuntimeException('The source workload could not be removed after the target became ready.');
            }

            $operation->node->workloads()->detach($operation->workload);
            if ($operation->workload->hasIngressRoutes() && $operation->node->cluster !== null) {
                // The source container IP is gone: drop its firewall allow on every Node.
                QueueNodeClusterNetworkRevision::run($operation->node->cluster, $operation->requestedBy);
            }
            $result = [
                'target_node_uuid' => $target->uuid,
                'deployment_operation_uuid' => $deploymentOperation->uuid,
                'removal_operation_uuid' => $removalOperation->uuid,
            ];
            $operation = TransitionOperation::run($operation, NodeOperationStatus::VERIFYING, result: $result);
            TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: $result);
        } catch (Throwable $exception) {
            if (! $targetWasAssigned && ! $targetDeploymentSucceeded && isset($target)) {
                $target->workloads()->detach($operation->workload);
                if ($networkRevisionQueued && $target->cluster !== null) {
                    // Withdraw the firewall allow for the address that the target no longer uses.
                    QueueNodeClusterNetworkRevision::run($target->cluster, $operation->requestedBy);
                }
            }
            $operation->refresh();
            if (! $operation->status->isFinal()) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage(), 0, 2000));
            }
        }
    }

    /**
     * Publishes a network revision and waits until every Node that it was queued for applied it,
     * up to the ingress step. Nodes that go offline meanwhile are no longer waited for.
     */
    private function waitForNetworkRevision(NodeCluster $cluster, ?User $user): void
    {
        $nodes = $cluster->nodes()->orderBy('id')->get()
            ->filter(fn (Node $node): bool => $node->canReceiveNetworkCommands());
        $attempts = $nodes->pluck('network_attempts', 'id');
        if (! QueueNodeClusterNetworkRevision::run($cluster, $user)) {
            // The network does not converge automatically: no run would apply the revision.
            return;
        }
        $revision = $cluster->desired_revision;

        for ($poll = 0; $poll <= self::NETWORK_WAIT_SECONDS; $poll++) {
            $pending = Node::query()->whereKey($nodes->pluck('id'))->orderBy('id')->get()
                ->filter(fn (Node $node): bool => $node->canReceiveNetworkCommands() && ! $node->hasAppliedNetworkRevision($revision));
            $failed = $pending->first(fn (Node $node): bool => $node->network_status === 'error'
                && $node->network_attempts > (int) $attempts->get($node->id));
            if ($failed !== null) {
                throw new RuntimeException("Node {$failed->name} could not apply the network for the target: {$failed->network_error} The source remains active.");
            }
            if ($pending->isEmpty()) {
                return;
            }
            if ($poll < self::NETWORK_WAIT_SECONDS) {
                Sleep::for(1)->second();
            }
        }

        throw new RuntimeException('The cluster network did not allow the target within '.self::NETWORK_WAIT_SECONDS.' seconds. Waiting for: '
            .$pending->pluck('name')->implode(', ').'. The source remains active.');
    }

    public function failed(?Throwable $exception): void
    {
        $operation = NodeOperation::query()->find($this->operationId);
        if ($operation !== null && ! $operation->status->isFinal()) {
            TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: 'The workload move worker stopped before completion.');
        }
    }
}
