<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Enums\NodeWorkloadDesiredState;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Models\Node;
use App\Models\NodeContainer;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use Lorisleiva\Actions\Concerns\AsAction;

class ReconcileNodeWorkloads
{
    use AsAction;

    public function handle(Node $node): int
    {
        $workloads = $node->workloads()
            ->with([
                'revisions' => fn ($query) => $query->latest('id'),
                'containers' => fn ($query) => $query
                    ->where('node_id', $node->id)
                    ->where('is_managed', true),
            ])
            ->get();
        $activeOperations = NodeOperation::query()
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
            ->latest('id')
            ->get()
            ->unique('node_workload_id')
            ->keyBy('node_workload_id');
        $operationCount = 0;

        foreach ($workloads as $workload) {
            $activeOperation = $activeOperations->get($workload->id);
            if ($activeOperation !== null) {
                $operationCount += $this->retryUncertainOperation($activeOperation);

                continue;
            }

            $revision = $workload->revisions->first();
            if ($revision === null) {
                continue;
            }

            $operationCount += $this->reconcileWorkload($node, $workload, $revision);
        }

        return $operationCount;
    }

    private function retryUncertainOperation(NodeOperation $operation): int
    {
        if ($operation->status !== NodeOperationStatus::UNCERTAIN) {
            return 0;
        }

        match ($operation->command_type) {
            'workload.deploy.v1' => DeployNodeWorkloadJob::dispatch($operation->id),
            'workload.lifecycle.v1' => ManageNodeWorkloadJob::dispatch($operation->id),
        };

        return 1;
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
            ),
            NodeWorkloadDesiredState::STOPPED => $container !== null
                && ! in_array($container->state, ['exited', 'stopped'], true)
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
    ): int {
        if ($currentContainer?->state === 'running') {
            return 0;
        }
        if ($currentContainer !== null) {
            return $this->dispatchLifecycle($node, $currentContainer, $latestRevision, NodeWorkloadAction::START);
        }

        $deployment = CreateDeploymentOperation::run($node, $latestRevision);
        if (! $deployment['created']) {
            return 0;
        }

        DeployNodeWorkloadJob::dispatch($deployment['operation']->id);

        return 1;
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
