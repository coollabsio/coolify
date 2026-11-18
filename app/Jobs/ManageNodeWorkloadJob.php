<?php

namespace App\Jobs;

use App\Actions\Node\ClaimOperation;
use App\Actions\Node\DispatchWorkloadLifecycle;
use App\Actions\Node\FetchContainers;
use App\Actions\Node\SyncNodeWorkloadSet;
use App\Actions\Node\TransitionOperation;
use App\Actions\Node\VerifyWorkloadLifecycleConvergence;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Enums\NodeWorkloadDesiredState;
use App\Models\NodeOperation;
use App\Models\NodeWorkloadSetEntry;
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

    public int $timeout = 300;

    public function __construct(public int $operationId)
    {
        // A Compose `stop_grace_period` (decision 0028) can be up to 600 seconds: the stop waits for it.
        $stopTimeout = data_get(NodeOperation::query()->find($operationId)?->request, 'stop_timeout_seconds');
        if (is_int($stopTimeout)) {
            $this->timeout = max($this->timeout, $stopTimeout + 150);
        }
    }

    public function handle(): void
    {
        $operation = NodeOperation::query()->with(['node', 'workload', 'revision'])->findOrFail($this->operationId);
        if ($operation->status->isFinal()) {
            return;
        }

        $entry = NodeWorkloadSetEntry::query()->where('node_id', $operation->node_id)->where('node_workload_id', $operation->node_workload_id)->first();
        $action = NodeWorkloadAction::tryFrom((string) data_get($operation->request, 'action'));
        if ($entry !== null && in_array($action, [NodeWorkloadAction::STOP, NodeWorkloadAction::START, NodeWorkloadAction::REMOVE], true)
            && $operation->node->supportsCapability(SyncNodeWorkloadSet::CAPABILITY) === true) {
            $this->converge($operation, $entry, $action);

            return;
        }

        try {
            if ($operation->status === NodeOperationStatus::UNCERTAIN) {
                try {
                    $verification = $this->verify($operation);
                    if ($verification['converged']) {
                        ClaimOperation::run(
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

            if (ClaimOperation::run($operation, NodeOperationStatus::DISPATCHED) === null) {
                return;
            }
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
            $reason = trim(mb_substr($exception->response->body(), 0, 2000));
            TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: 'Flux rejected the workload lifecycle command with HTTP '.$exception->response->status().($reason === '' ? '.' : ": {$reason}"));
        } catch (Throwable $exception) {
            TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage(), 0, 2000));
        }
    }

    /**
     * Sentinel converges the workload (decision 0026): a stop or a start changes the desired state of
     * its entry, and a removal removes the entry. The status events of Sentinel end the operation;
     * the job waits for them, so a move that runs it inline gets the result.
     */
    private function converge(NodeOperation $operation, NodeWorkloadSetEntry $entry, NodeWorkloadAction $action): void
    {
        try {
            if (ClaimOperation::run($operation, NodeOperationStatus::DISPATCHED) === null) {
                return;
            }
            if ($action === NodeWorkloadAction::REMOVE) {
                $entry->delete();
            } else {
                $entry->update([
                    'desired_state' => $action === NodeWorkloadAction::STOP ? NodeWorkloadDesiredState::STOPPED : NodeWorkloadDesiredState::RUNNING,
                    'node_operation_id' => $operation->id,
                    'redeploy' => false,
                ]);
            }
            $sync = SyncNodeWorkloadSet::run($operation->node, [$operation->node_workload_id]);
            $operation->refresh();
            $result = [...($operation->result ?? []), 'command_id' => $operation->uuid, 'converged_by' => 'sentinel', 'workload_set_generation' => $sync['generation']];
            if (! $sync['sent']) {
                if (! $operation->status->isFinal()) {
                    TransitionOperation::run($operation, NodeOperationStatus::UNCERTAIN, result: $result, error: 'Sentinel is not connected. It applies the change when it connects again.');
                }

                return;
            }
            if ($operation->status === NodeOperationStatus::DISPATCHED) {
                $operation = TransitionOperation::run($operation, NodeOperationStatus::RUNNING, result: $result);
            }
            $deadline = now()->addSeconds($this->timeout - 30);
            while (! $operation->refresh()->status->isFinal() && now()->lt($deadline)) {
                Sleep::for(1)->seconds();
            }
        } catch (RequestException $exception) {
            $reason = trim(mb_substr($exception->response->body(), 0, 2000));
            if (! $operation->refresh()->status->isFinal()) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: 'Flux rejected the desired set with HTTP '.$exception->response->status().($reason === '' ? '.' : ": {$reason}"));
            }
        } catch (Throwable $exception) {
            if (! $operation->refresh()->status->isFinal()) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage(), 0, 2000));
            }
        }
    }

    /** @return array{converged: bool, observed_at: mixed, runtime_id: ?string, state: string, image: ?string} */
    private function verify(NodeOperation $operation): array
    {
        try {
            FetchContainers::run($operation->node);
        } catch (ConnectionException|RequestException) {
            // Sentinel runs one command at a time, so the inventory read can time out behind a
            // long command. The stored inventory is older; the next attempt reads again.
        }

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
