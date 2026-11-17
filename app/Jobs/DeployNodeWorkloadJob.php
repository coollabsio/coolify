<?php

namespace App\Jobs;

use App\Actions\Node\ApplyNodeClusterNetworkRevision;
use App\Actions\Node\Build\FetchNodeImage;
use App\Actions\Node\ClaimOperation;
use App\Actions\Node\DispatchWorkloadDeployment;
use App\Actions\Node\DispatchWorkloadLifecycle;
use App\Actions\Node\FetchContainers;
use App\Actions\Node\SyncNodeWorkloadSet;
use App\Actions\Node\TransitionOperation;
use App\Actions\Node\VerifyDeploymentConvergence;
use App\Enums\NodeContainerSlot;
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

    /** The image copy from a peer, the Flux deploy request (620 s), the health wait, and the network revision of published ports. */
    public int $timeout = FetchNodeImage::MAX_SECONDS + 1020 + ApplyNodeClusterNetworkRevision::WAIT_SECONDS;

    /** @param  Carbon|null  $waitUntil  A move that runs the job inline ends the health wait at its own deadline. */
    public function __construct(public int $operationId, public ?Carbon $waitUntil = null) {}

    public function handle(): void
    {
        $operation = NodeOperation::query()->with(['node', 'workload', 'revision'])->findOrFail($this->operationId);
        if ($operation->status->isFinal()) {
            return;
        }
        $fetched = $this->fetchImage($operation);
        if ($fetched === null) {
            return;
        }
        $operation->refresh();

        if (SyncNodeWorkloadSet::converges($operation->node, $operation->workload)) {
            $this->converge($operation);

            return;
        }
        // The command path deploys it now, so Sentinel must not converge it to an older entry.
        rescue(fn () => SyncNodeWorkloadSet::release($operation->node, $operation->workload));

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
            $result = [...$fetched, ...DispatchWorkloadDeployment::run($operation)];
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

    /**
     * Copies a local image that the Node does not have from a peer Node first (decision 0030), before
     * Sentinel gets the revision. Returns the result to keep, or null when the copy failed the operation.
     *
     * @return array<string, mixed>|null
     */
    private function fetchImage(NodeOperation $operation): ?array
    {
        $deadline = now()->addSeconds(FetchNodeImage::MAX_SECONDS);
        if ($this->waitUntil !== null && $this->waitUntil->lt($deadline)) {
            $deadline = $this->waitUntil;
        }
        try {
            $copy = FetchNodeImage::run($operation->node, $operation->revision, $deadline);
        } catch (Throwable $exception) {
            if ($operation->refresh()->status->canTransitionTo(NodeOperationStatus::FAILED)) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage(), 0, 2000));
            }

            return null;
        }
        if ($copy === null) {
            return [];
        }
        $fetched = [
            'fetched_image' => $copy['source']->name,
            'fetched_image_seconds' => $copy['seconds'],
            'fetched_image_size' => $copy['size_bytes'],
            'fetched_image_sent' => $copy['bytes_sent'],
        ];
        $operation->update(['result' => [...($operation->refresh()->result ?? []), ...$fetched]]);

        return $fetched;
    }

    /**
     * Sentinel converges the workload (decision 0026): the deploy is an entry of the desired set of
     * the Node. Sentinel deploys it without downtime, judges the health and crash loops, and removes
     * the replaced container. Its status events update this operation. The job waits for the end, so
     * a move or a stack deploy that runs it inline gets the result.
     */
    private function converge(NodeOperation $operation): void
    {
        try {
            if (ClaimOperation::run($operation, NodeOperationStatus::DISPATCHED) === null) {
                return;
            }
            $prepared = DispatchWorkloadDeployment::make()->prepare($operation);
            NodeWorkloadSetEntry::query()->updateOrCreate(
                ['node_id' => $operation->node_id, 'node_workload_id' => $operation->node_workload_id],
                [
                    'node_workload_revision_id' => $operation->node_workload_revision_id,
                    'node_operation_id' => $operation->id,
                    'desired_state' => NodeWorkloadDesiredState::RUNNING,
                    'container_slot' => $prepared['plan']['slot'],
                    'replaces_slot' => $prepared['plan']['replaces'],
                    'network_revision' => $prepared['network_revision'],
                    'pull_policy' => data_get($operation->request, 'pull_policy', 'missing'),
                    'redeploy' => true,
                ],
            );
            $sync = SyncNodeWorkloadSet::run($operation->node, [$operation->node_workload_id], [$operation->node_workload_id => $prepared]);
            $operation->refresh();
            $result = [...($operation->result ?? []), 'command_id' => $operation->uuid, 'converged_by' => 'sentinel', 'workload_set_generation' => $sync['generation'], 'name' => $prepared['name']];
            if (! $sync['sent']) {
                if (! $operation->status->isFinal()) {
                    TransitionOperation::run($operation, NodeOperationStatus::UNCERTAIN, result: $result, error: 'Sentinel is not connected. It deploys the revision when it connects again.');
                }

                return;
            }
            if ($operation->status === NodeOperationStatus::DISPATCHED) {
                $operation = TransitionOperation::run($operation, NodeOperationStatus::RUNNING, result: $result);
            }
            $this->waitForSentinel($operation, now()->addSeconds($this->healthWaitSeconds($operation) + 600));
        } catch (RequestException $exception) {
            $reason = trim(mb_substr($exception->response->body(), 0, 2000));
            $operation->refresh();
            if (! $operation->status->isFinal()) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: 'Flux rejected the desired set with HTTP '.$exception->response->status().($reason === '' ? '.' : ": {$reason}"));
            }
        } catch (Throwable $exception) {
            $operation->refresh();
            if (! $operation->status->isFinal()) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage(), 0, 2000));
            }
        }
    }

    /** Waits until the status events of Sentinel end the operation, or until the deadline. */
    private function waitForSentinel(NodeOperation $operation, Carbon $deadline): void
    {
        if ($this->waitUntil !== null && $this->waitUntil->lt($deadline)) {
            $deadline = $this->waitUntil;
        }
        while (! $operation->refresh()->status->isFinal() && now()->lt($deadline)) {
            Sleep::for(self::POLL_SECONDS)->seconds();
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
     * A published port forwards to one container (decision 0016), so the Nodes forward it to the
     * new container first.
     *
     * @return array<string, mixed>
     */
    private function retireReplaced(NodeOperation $operation): array
    {
        $plan = $this->rollingPlan($operation);
        if ($plan === null) {
            return [];
        }
        $cluster = $operation->node->cluster;
        if ($cluster !== null && $operation->workload->publishedPorts()->exists()) {
            try {
                ApplyNodeClusterNetworkRevision::run($cluster, $operation->requestedBy);
            } catch (Throwable $exception) {
                report($exception);
            }
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
        try {
            FetchContainers::run($operation->node);
        } catch (ConnectionException|RequestException) {
            // Sentinel runs one command at a time, so the inventory read can time out behind a
            // long command, such as a drain and stop. The result is not known yet: poll again.
            $verification = VerifyDeploymentConvergence::run($operation);

            return $verification['converged'] ? $verification : [...$verification, 'pending' => true];
        }

        return VerifyDeploymentConvergence::run($operation);
    }

    /**
     * Polls the inventory until the container is healthy, like v4 waits for the health check of
     * a new container. An unhealthy or restarted container fails at once. A container that stops, or one
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
                || ($verification['restarts'] ?? 0) > 0
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
            ($verification['restarts'] ?? 0) > 0 => 'The application stopped right after the start, and Podman restarted it. Check the logs of the application: its start command or its configuration can be wrong.',
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
