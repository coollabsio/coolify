<?php

namespace App\Jobs;

use App\Actions\Node\Build\FetchNodeImage;
use App\Actions\Node\Build\IssueNodeGitCredential;
use App\Actions\Node\Build\ReadGitComposeFile;
use App\Actions\Node\Build\StartNodeBuild;
use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\NotifyNodeDeployment;
use App\Actions\Node\PrepareNodeWorkloadRevision;
use App\Actions\Node\SyncNodeStackCompose;
use App\Enums\NodeBuildStatus;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadSourceType;
use App\Models\NodeBuild;
use App\Models\NodeOperation;
use App\Models\NodeStack;
use App\Models\NodeWorkload;
use App\Models\User;
use App\Services\NodeStack\NodeStackCompose;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 *
 * A stack from Git (step 2) first reads its Compose file at the head of its branch and makes the
 * services match it (SyncNodeStackCompose). The commit of that read is the commit of the deploy: a
 * webhook commit only starts the deploy, and the head is the same commit or a newer one. Then it
 * builds every service with `build:` at that commit, without deploying them. Each build is a
 * normal build of its service, with its logs on the service page. When one fails, nothing deploys.
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

    public function __construct(public int $stackId, public ?int $requestedById = null, public ?string $commitSha = null)
    {
        // The sync of a stack from Git can add services after the job is queued, so it gets the
        // longest time. The deadline is only a ceiling: each service still has its own wait.
        if (NodeStack::query()->withoutGlobalScopes()->whereKey($stackId)->whereNotNull('git_repository')->exists()) {
            $this->timeout = self::MAX_TIMEOUT;

            return;
        }
        $sourceTypes = NodeWorkload::query()->withoutGlobalScopes()->where('node_stack_id', $stackId)->pluck('source_type');
        $builds = $sourceTypes->filter(fn (NodeWorkloadSourceType $type): bool => $type->builds())->count();
        // A built service can deploy on another server than its build: the copy of its image comes first.
        $buildSeconds = $builds * ((int) config('constants.node.build_timeout_minutes') * 60 + FetchNodeImage::MAX_SECONDS);
        $this->timeout = min(max($this->timeout, $sourceTypes->count() * self::SERVICE_WAIT_SECONDS + $buildSeconds + 120), self::MAX_TIMEOUT);
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
        $stack->update(['deploy_status' => 'deploying', 'deploy_error' => null, 'deploy_notified_at' => null]);

        try {
            $deadline = now()->addSeconds($this->timeout - 60);
            $commit = $stack->hasGitSource() ? $this->syncCompose($stack, $requestedBy) : null;
            if (NodeStackCompose::mountsContainerSocket($stack->compose_parsed ?? [])) {
                // Before the first service deploys, so a refused stack does not deploy half of it.
                NodeStackCompose::ensureMayMountContainerSocket($requestedBy, (int) $stack->team_id, 'This stack');
            }
            $dependencies = $stack->dependencies();
            $order = $stack->deployOrder($dependencies);
            // A service that left the Compose file is deleted by its own job.
            $workloads = NodeWorkload::query()->withoutGlobalScopes()->where('node_stack_id', $stack->id)->whereIn('stack_service', $order)->get()->keyBy('stack_service');
            if ($commit !== null) {
                $this->build($stack, $workloads, $requestedBy, $commit, $deadline);
            }
            foreach ($order as $service) {
                $workload = $workloads->get($service);
                if ($workload === null || $this->keepsRunningOnPush($workload)) {
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
        NotifyNodeDeployment::forStack($stack);
    }

    /**
     * Reads the Compose file at the head of the branch and makes the services match it.
     *
     * @return string The commit of the file, for every build of this deploy.
     */
    private function syncCompose(NodeStack $stack, ?User $requestedBy): string
    {
        $file = ReadGitComposeFile::run(
            (string) $stack->git_repository,
            (string) $stack->git_branch,
            IssueNodeGitCredential::run(new NodeWorkload(['team_id' => $stack->team_id, 'github_app_id' => $stack->github_app_id, 'private_key_id' => $stack->private_key_id])),
            $stack->base_directory,
            $stack->compose_location,
        );
        SyncNodeStackCompose::run($stack, $file['content'], $file['commit_sha'], $requestedBy);
        $stack->refresh();

        return $file['commit_sha'];
    }

    /**
     * Builds every service with `build:` at one commit and waits for the builds.
     *
     * @param  Collection<string, NodeWorkload>  $workloads
     */
    private function build(NodeStack $stack, Collection $workloads, ?User $requestedBy, string $commit, Carbon $deadline): void
    {
        $built = $workloads->filter(fn (NodeWorkload $workload): bool => $workload->buildsFromGit());
        if ($built->isEmpty()) {
            $stack->update(['commit_sha' => $commit]);

            return;
        }
        // A running build of a service is reused: the service has at most one active build.
        $builds = $built->map(fn (NodeWorkload $workload): NodeBuild => StartNodeBuild::run($workload, $requestedBy, $commit, skipDeploy: true));
        while ($builds->contains(fn (NodeBuild $build): bool => ! $build->refresh()->status->isFinal())) {
            if (now()->gte($deadline)) {
                throw new RuntimeException('The builds of '.$builds->filter(fn (NodeBuild $build): bool => ! $build->status->isFinal())->keys()->implode(', ').' did not finish in time. Nothing was deployed.');
            }
            Sleep::for(3)->seconds();
        }
        $failed = $builds->filter(fn (NodeBuild $build): bool => $build->status !== NodeBuildStatus::SUCCEEDED);
        if ($failed->isNotEmpty()) {
            throw new RuntimeException('The build of '.$failed->keys()->implode(', ').' failed. Nothing was deployed. See the build logs of '.($failed->count() === 1 ? 'the service' : 'each service').'.');
        }
        $stack->update(['commit_sha' => $commit]);
    }

    /**
     * A push changes only the services that build from Git. As `docker compose up` does, a
     * deployed image service without a configuration change keeps running, so a database does
     * not restart for each push.
     */
    private function keepsRunningOnPush(NodeWorkload $workload): bool
    {
        return $this->commitSha !== null
            && ! $workload->buildsFromGit()
            && $workload->deployedRevision() !== null
            && ! $workload->isConfigurationChanged();
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
        $stack = NodeStack::query()->withoutGlobalScopes()->find($this->stackId);
        if ($stack !== null) {
            NotifyNodeDeployment::forStack($stack);
        }
    }
}
