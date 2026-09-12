<?php

namespace App\Jobs;

use App\Actions\Node\DispatchWorkloadDeployment;
use App\Actions\Node\FetchContainers;
use App\Actions\Node\TransitionOperation;
use App\Enums\NodeOperationStatus;
use App\Models\NodeOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeployNodeWorkloadJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 660;

    public function __construct(public int $operationId) {}

    public function handle(): void
    {
        $operation = NodeOperation::query()->with(['node', 'workload', 'revision'])->findOrFail($this->operationId);
        if ($operation->status->isFinal()) {
            return;
        }

        try {
            TransitionOperation::run($operation, NodeOperationStatus::DISPATCHED);
            $operation = TransitionOperation::run($operation, NodeOperationStatus::RUNNING);
            $result = DispatchWorkloadDeployment::run($operation);
            TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: $result);

            try {
                FetchContainers::run($operation->node);
            } catch (Throwable $exception) {
                report($exception);
            }
        } catch (ConnectionException) {
            TransitionOperation::run($operation, NodeOperationStatus::UNCERTAIN, error: 'The deployment result is unknown.');
        } catch (RequestException $exception) {
            $message = 'Flux rejected the deployment with HTTP '.$exception->response->status().'.';
            TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: $message);
        } catch (Throwable $exception) {
            TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage(), 0, 2000));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $operation = NodeOperation::query()->find($this->operationId);
        if ($operation !== null && ! $operation->status->isFinal()) {
            if ($operation->status === NodeOperationStatus::QUEUED) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: 'The deployment worker stopped before dispatch.');
            } elseif ($operation->status !== NodeOperationStatus::UNCERTAIN) {
                TransitionOperation::run($operation, NodeOperationStatus::UNCERTAIN, error: 'The deployment worker stopped before it received a result.');
            }
        }
    }
}
