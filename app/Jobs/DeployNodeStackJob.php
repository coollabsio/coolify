<?php

namespace App\Jobs;

use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\PrepareNodeWorkloadRevision;
use App\Enums\NodeOperationStatus;
use App\Models\NodeOperation;
use App\Models\NodeStack;
use App\Models\NodeWorkload;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Deploys the services of a stack in `depends_on` order (decision 0023), one deployment for each
 * service through the deployment of a cluster application. A service deploys after each service
 * that it depends on reached its condition:
 * - `service_started`: the container runs;
 * - `service_healthy`: the health check passed;
 * - `service_completed_successfully`: the one-time container exited with 0.
 *
 * DeployNodeWorkloadJob waits for exactly that: a running container after its settle time, a
 * healthy container when it has a health check, and an exit code of 0 for restart policy `no`.
 */
class DeployNodeStackJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Longest wait for a deployment of a service that another worker runs. */
    public const SERVICE_WAIT_SECONDS = 1080;

    /** Below the Horizon worker timeout, like a stateful move. */
    public const MAX_TIMEOUT = MoveNodeWorkloadJob::STATEFUL_TIMEOUT;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public int $stackId, public ?int $requestedById = null)
    {
        $services = NodeWorkload::query()->withoutGlobalScopes()->where('node_stack_id', $stackId)->count();
        $this->timeout = min(max($this->timeout, $services * self::SERVICE_WAIT_SECONDS + 120), self::MAX_TIMEOUT);
    }

    public function uniqueId(): string
    {
        return (string) $this->stackId;
    }

    public function uniqueFor(): int
    {
        return $this->timeout + 60;
    }

    public function handle(): void
    {
        $stack = NodeStack::query()->withoutGlobalScopes()->find($this->stackId);
        if ($stack === null) {
            return;
        }
        $requestedBy = $this->requestedById === null ? null : User::query()->find($this->requestedById);
        $stack->update(['deploy_status' => 'deploying', 'deploy_error' => null]);

        try {
            $dependencies = $stack->dependencies();
            $order = $stack->deployOrder($dependencies);
            $workloads = NodeWorkload::query()->withoutGlobalScopes()->where('node_stack_id', $stack->id)->get()->keyBy('stack_service');
            $deadline = now()->addSeconds($this->timeout - 60);
            foreach ($order as $service) {
                $workload = $workloads->get($service);
                if ($workload === null) {
                    continue;
                }
                $operation = $this->deploy($workload, $requestedBy, $deadline);
                if ($operation->status !== NodeOperationStatus::SUCCEEDED) {
                    $waiting = collect($dependencies)
                        ->filter(fn (array $serviceDependencies): bool => array_key_exists($service, $serviceDependencies))
                        ->keys();
                    throw new RuntimeException("The service {$service} did not ".$this->condition($service, $dependencies).': '
                        .($operation->error ?? 'the deployment did not finish in time.')
                        .($waiting->isEmpty() ? '' : ' Not deployed: '.$waiting->implode(', ').'.'));
                }
            }
            $stack->update(['deploy_status' => 'deployed', 'deploy_error' => null]);
        } catch (Throwable $exception) {
            $stack->update(['deploy_status' => 'failed', 'deploy_error' => mb_substr($exception->getMessage(), 0, 2000)]);
        }
    }

    private function deploy(NodeWorkload $workload, ?User $requestedBy, Carbon $deadline): NodeOperation
    {
        $node = $workload->nodes()->orderBy('nodes.id')->first();
        if ($node === null) {
            throw new RuntimeException("The service {$workload->stack_service} has no server.");
        }
        $revision = PrepareNodeWorkloadRevision::run($workload);
        $deployment = CreateDeploymentOperation::run($node, $revision, $requestedBy, 'newer');
        if ($deployment['created']) {
            (new DeployNodeWorkloadJob($deployment['operation']->id, $deadline))->handle();
        }
        $operation = $deployment['operation'];
        for ($poll = 0; $poll < self::SERVICE_WAIT_SECONDS && now()->lt($deadline); $poll++) {
            $operation->refresh();
            if (! $operation->status->isActive()) {
                return $operation;
            }
            Sleep::for(1)->second();
        }

        return $operation->refresh();
    }

    /**
     * What the dependents of a service wait for, for the failure message.
     *
     * @param  array<string, array<string, string>>  $dependencies
     */
    private function condition(string $service, array $dependencies): string
    {
        $conditions = collect($dependencies)->pluck($service)->filter()->unique();

        return match (true) {
            $conditions->contains('service_completed_successfully') => 'complete successfully',
            $conditions->contains('service_healthy') => 'become healthy',
            default => 'start',
        };
    }

    public function failed(?Throwable $exception): void
    {
        NodeStack::query()->withoutGlobalScopes()->whereKey($this->stackId)->update([
            'deploy_status' => 'failed',
            'deploy_error' => mb_substr('The stack deployment worker stopped: '.($exception?->getMessage() ?? ''), 0, 2000),
        ]);
    }
}
