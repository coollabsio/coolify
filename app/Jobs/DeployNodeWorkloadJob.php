<?php

namespace App\Jobs;

use App\Actions\Node\ClaimOperation;
use App\Actions\Node\DispatchWorkloadDeployment;
use App\Actions\Node\DispatchWorkloadLifecycle;
use App\Actions\Node\FetchContainers;
use App\Actions\Node\TransitionOperation;
use App\Actions\Node\VerifyDeploymentConvergence;
use App\Enums\NodeContainerSlot;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Models\NodeOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Throwable;

class DeployNodeWorkloadJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** A container without a health check must keep running this long after the deploy. */
    public const SETTLE_SECONDS = 10;

    /** Longest wait for a health check to pass. Keeps the job below the stale operation limit. */
    public const MAX_HEALTH_WAIT_SECONDS = 300;

    public const POLL_SECONDS = 2;

    public int $tries = 1;

    /** The Flux deploy request (620 s) and the health wait. */
    public int $timeout = 1020;

    /** @param  Carbon|null  $waitUntil  A move that runs the job inline ends the health wait at its own deadline. */
    public function __construct(public int $operationId, public ?Carbon $waitUntil = null) {}

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
                        ClaimOperation::run(
                            $operation,
                            NodeOperationStatus::SUCCEEDED,
                            result: [...($operation->result ?? []), 'verification' => $verification, ...$this->retireReplaced($operation)],
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
            $result = DispatchWorkloadDeployment::run($operation);
            $operation = TransitionOperation::run($operation, NodeOperationStatus::VERIFYING, result: $result);
            $verification = $this->verifyUntilHealthy($operation);
            $result['verification'] = $verification;
            if (! $verification['converged']) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, result: $result, error: $this->failureReason($operation, $verification).$this->discardNew($operation));

                return;
            }

            TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: [...$result, ...$this->retireReplaced($operation)]);
        } catch (ConnectionException) {
            if ($operation->refresh()->status !== NodeOperationStatus::UNCERTAIN) {
                TransitionOperation::run($operation, NodeOperationStatus::UNCERTAIN, error: 'The deployment result is unknown.');
            }
        } catch (RequestException $exception) {
            $reason = trim(mb_substr($exception->response->body(), 0, 2000));
            $message = 'Flux rejected the deployment with HTTP '.$exception->response->status().($reason === '' ? '.' : ": {$reason}");
            TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: $message.$this->discardNew($operation));
        } catch (Throwable $exception) {
            TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage(), 0, 2000).$this->discardNew($operation));
        }
    }

    /** @return array{slot: NodeContainerSlot, replaces: NodeContainerSlot}|null */
    private function rollingPlan(NodeOperation $operation): ?array
    {
        $plan = data_get($operation->refresh()->request, 'container');
        $replaces = NodeContainerSlot::tryFrom((string) data_get($plan, 'replaces'));

        return $replaces === null ? null : ['slot' => NodeContainerSlot::from($plan['slot']), 'replaces' => $replaces];
    }

    /**
     * Removes the container that a rolling deploy replaced, after the new one is healthy. Sentinel
     * drains its endpoint first. A failure leaves it running: the next rolling deploy replaces it.
     *
     * @return array<string, mixed>
     */
    private function retireReplaced(NodeOperation $operation): array
    {
        $plan = $this->rollingPlan($operation);
        if ($plan === null) {
            return [];
        }
        $name = $plan['replaces']->containerName($operation->workload);
        try {
            DispatchWorkloadLifecycle::send($operation->node, "{$operation->uuid}-retire", $name, NodeWorkloadAction::REMOVE);
            $retired = ['replaced_container' => $name];
        } catch (Throwable $exception) {
            report($exception);
            $retired = ['replaced_container' => $name, 'replaced_container_error' => mb_substr($exception->getMessage(), 0, 2000)];
        }
        $this->refreshInventory($operation);

        return $retired;
    }

    /** Removes the new container of a failed rolling deploy, so the old one keeps serving alone. */
    private function discardNew(NodeOperation $operation): string
    {
        $plan = $this->rollingPlan($operation);
        if ($plan === null) {
            return '';
        }
        try {
            DispatchWorkloadLifecycle::send($operation->node, "{$operation->uuid}-discard", $plan['slot']->containerName($operation->workload), NodeWorkloadAction::REMOVE);
        } catch (Throwable $exception) {
            report($exception);
        }
        $this->refreshInventory($operation);

        return ' The previous version keeps running.';
    }

    private function refreshInventory(NodeOperation $operation): void
    {
        try {
            FetchContainers::run($operation->node);
        } catch (Throwable) {
            // The scheduled inventory refresh catches up.
        }
    }

    /** @return array<string, mixed> */
    private function verify(NodeOperation $operation): array
    {
        FetchContainers::run($operation->node);

        return VerifyDeploymentConvergence::run($operation);
    }

    /**
     * Polls the inventory until the container is healthy, like v4 waits for the health check of
     * a new container. An unhealthy container fails at once. A container that stops, or one
     * without a health check, is judged after the settle time, so a crash right after the start
     * is not reported as a success.
     *
     * @return array<string, mixed>
     */
    private function verifyUntilHealthy(NodeOperation $operation): array
    {
        $settleUntil = now()->addSeconds(self::SETTLE_SECONDS);
        $deadline = now()->addSeconds($this->healthWaitSeconds($operation));
        if ($this->waitUntil !== null && $this->waitUntil->lt($deadline)) {
            $deadline = $this->waitUntil;
        }
        while (true) {
            $verification = $this->verify($operation);
            $settled = now()->gte($settleUntil);
            if ($verification['health'] === 'unhealthy'
                || ($settled && ! $verification['pending'])
                || now()->gte($deadline)) {
                return $verification;
            }
            Sleep::for(self::POLL_SECONDS)->seconds();
        }
    }

    /** The time that the health check of the revision may take: the start period and every retry. */
    private function healthWaitSeconds(NodeOperation $operation): int
    {
        $check = data_get($operation->revision->configuration, 'health_check');
        if (! is_array($check)) {
            return self::MAX_HEALTH_WAIT_SECONDS;
        }
        $seconds = (int) $check['start_period_seconds'] + ((int) $check['interval_seconds'] + (int) $check['timeout_seconds']) * (int) $check['retries'] + 30;

        return max(self::SETTLE_SECONDS, min($seconds, self::MAX_HEALTH_WAIT_SECONDS));
    }

    /** @param  array<string, mixed>  $verification */
    private function failureReason(NodeOperation $operation, array $verification): string
    {
        $check = data_get($operation->revision->configuration, 'health_check.description');
        $checked = is_string($check) && $check !== '' ? " Health check (inside the container): {$check}." : '';
        $tools = is_string($check) && str_contains((string) data_get($operation->revision->configuration, 'health_check.command'), 'curl ')
            ? ' The HTTP health check needs curl or wget in the image. Install one of them, or turn off the health check.'
            : '';

        return match (true) {
            $verification['health'] === 'unhealthy' => "The application is unhealthy: its health check failed.{$checked}{$tools}",
            $verification['pending'] => 'The health check did not pass in time.'.$checked.$tools,
            default => 'The requested workload revision is not running.',
        };
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
