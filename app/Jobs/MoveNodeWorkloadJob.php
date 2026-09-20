<?php

namespace App\Jobs;

use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\CreateLifecycleOperation;
use App\Actions\Node\TransitionOperation;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Models\Node;
use App\Models\NodeOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class MoveNodeWorkloadJob implements ShouldQueue
{
    use Queueable, SerializesModels;

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
        try {
            $target = Node::query()
                ->where('uuid', data_get($operation->request, 'target_node_uuid'))
                ->where('team_id', $operation->node->team_id)
                ->where('node_cluster_id', $operation->node->node_cluster_id)
                ->firstOrFail();
            TransitionOperation::run($operation, NodeOperationStatus::DISPATCHED);
            $operation = TransitionOperation::run($operation, NodeOperationStatus::RUNNING);

            $targetWasAssigned = $target->workloads()->whereKey($operation->workload->id)->exists();
            if (! $targetWasAssigned) {
                $target->workloads()->attach($operation->workload);
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
            }
            $operation->refresh();
            if (! $operation->status->isFinal()) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage(), 0, 2000));
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $operation = NodeOperation::query()->find($this->operationId);
        if ($operation !== null && ! $operation->status->isFinal()) {
            TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: 'The workload move worker stopped before completion.');
        }
    }
}
