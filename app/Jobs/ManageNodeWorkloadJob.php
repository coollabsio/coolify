<?php

namespace App\Jobs;

use App\Actions\Node\DispatchWorkloadLifecycle;
use App\Actions\Node\FetchContainers;
use App\Actions\Node\TransitionOperation;
use App\Actions\Node\VerifyWorkloadLifecycleConvergence;
use App\Enums\NodeOperationStatus;
use App\Models\NodeOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Sleep;
use Throwable;

class ManageNodeWorkloadJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $operationId) {}

    public function handle(): void
    {
        $operation = NodeOperation::query()->with(['node', 'workload', 'revision'])->findOrFail($this->operationId);
        if ($operation->status->isFinal()) {
            return;
        }

        try {
            if ($operation->status === NodeOperationStatus::UNCERTAIN) {
                try {
                    $verification = $this->verify($operation);
                    if ($verification['converged']) {
                        TransitionOperation::run(
                            $operation,
                            NodeOperationStatus::SUCCEEDED,
                            result: [...($operation->result ?? []), 'verification' => $verification],
                        );

                        return;
                    }
                } catch (ConnectionException|RequestException) {
                    // Replaying the same command UUID is safe when inventory cannot confirm the result.
                }
            }

            TransitionOperation::run($operation, NodeOperationStatus::DISPATCHED);
            $operation = TransitionOperation::run($operation, NodeOperationStatus::RUNNING);
            $result = DispatchWorkloadLifecycle::run($operation);
            $operation = TransitionOperation::run($operation, NodeOperationStatus::VERIFYING, result: $result);
            $verification = $this->verifyUntilConverged($operation);
            $result['verification'] = $verification;
            if (! $verification['converged']) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, result: $result, error: 'The requested workload state was not reached.');

                return;
            }

            TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: $result);
        } catch (ConnectionException) {
            if ($operation->refresh()->status !== NodeOperationStatus::UNCERTAIN) {
                TransitionOperation::run($operation, NodeOperationStatus::UNCERTAIN, error: 'The workload lifecycle result is unknown.');
            }
        } catch (RequestException $exception) {
            TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: 'Flux rejected the workload lifecycle command with HTTP '.$exception->response->status().'.');
        } catch (Throwable $exception) {
            TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage(), 0, 2000));
        }
    }

    /** @return array{converged: bool, observed_at: mixed, runtime_id: ?string, state: string, image: ?string} */
    private function verify(NodeOperation $operation): array
    {
        FetchContainers::run($operation->node);

        return VerifyWorkloadLifecycleConvergence::run($operation);
    }

    /** @return array{converged: bool, observed_at: mixed, runtime_id: ?string, state: string, image: ?string} */
    private function verifyUntilConverged(NodeOperation $operation): array
    {
        for ($attempt = 1; $attempt <= 15; $attempt++) {
            $verification = $this->verify($operation);
            if ($verification['converged'] || $attempt === 15) {
                return $verification;
            }

            Sleep::for(1)->seconds();
        }

        throw new \LogicException('Lifecycle verification did not return a result.');
    }

    public function failed(?Throwable $exception): void
    {
        $operation = NodeOperation::query()->find($this->operationId);
        if ($operation !== null && ! $operation->status->isFinal()) {
            if ($operation->status === NodeOperationStatus::QUEUED) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: 'The lifecycle worker stopped before dispatch.');
            } elseif ($operation->status !== NodeOperationStatus::UNCERTAIN) {
                TransitionOperation::run($operation, NodeOperationStatus::UNCERTAIN, error: 'The lifecycle worker stopped before it received a result.');
            }
        }
    }
}
