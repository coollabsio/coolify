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
use App\Models\NodeStackDeployment;
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
 *
 * Each deploy is a NodeStackDeployment, for the deployment history of the stack.
 *
 * While the builds wait for a builder (decision 0035), the job ends and holds no queue worker.
 * The end of the last build of the deploy (success, failure, or cancel) dispatches the job again
 * with the same NodeStackDeployment (`resumeAfter()`); ProcessNodeBuildQueueJob resumes a deploy
 * whose dispatch was lost. The deploy keeps `deploy_status` `deploying`, so a second deploy is
 * refused, and it sends 1 notification when it ends.
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

    /**
     * @param  ?int  $deploymentId  The deploy that this run resumes after its builds ended. Null for a new deploy.
     */
    public function __construct(public int $stackId, public ?int $requestedById = null, public ?string $commitSha = null, public ?int $deploymentId = null)
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
        if ($this->deploymentId === null) {
            $stack->update(['deploy_status' => 'deploying', 'deploy_error' => null, 'deploy_notified_at' => null]);
            $deployment = NodeStackDeployment::query()->create([
                'node_stack_id' => $stack->id,
                'requested_by_id' => $requestedBy?->id,
                'status' => NodeStackDeployment::DEPLOYING,
                'is_push' => $this->commitSha !== null,
            ]);
        } else {
            // A second resume, or a resume of a deploy that ended, does nothing.
            $deployment = NodeStackDeployment::query()->where('node_stack_id', $stack->id)->find($this->deploymentId);
            if ($deployment === null || $deployment->status !== NodeStackDeployment::DEPLOYING) {
                return;
            }
        }

        try {
            $deadline = now()->addSeconds($this->timeout - 60);
            // A resumed deploy keeps the commit of its first run; it does not read the Compose file again.
            $commit = match (true) {
                ! $stack->hasGitSource() => null,
                $this->deploymentId !== null => (string) $deployment->commit_sha,
                default => $this->syncCompose($stack, $requestedBy),
            };
            if ($commit !== null && $this->deploymentId === null) {
                $deployment->update(['commit_sha' => $commit]);
            }
            if (NodeStackCompose::mountsContainerSocket($stack->compose_parsed ?? [])) {
                // Before the first service deploys, so a refused stack does not deploy half of it.
                NodeStackCompose::ensureMayMountContainerSocket($requestedBy, (int) $stack->team_id, 'This stack');
            }
            NodeStackCompose::ensureMayUseAdminIsolation($requestedBy, (int) $stack->team_id, $stack->compose_parsed ?? []);
            $dependencies = $stack->dependencies();
            $order = $stack->deployOrder($dependencies);
            // A service that left the Compose file is deleted by its own job.
            $workloads = NodeWorkload::query()->withoutGlobalScopes()->where('node_stack_id', $stack->id)->whereIn('stack_service', $order)->get()->keyBy('stack_service');
            if ($commit !== null && ! $this->build($stack, $deployment, $workloads, $requestedBy, $commit)) {
                // The builds wait for a builder: the end of the last one resumes this deploy.
                return;
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
            $deployment->update([
                'status' => NodeStackDeployment::DEPLOYED,
                'commit_sha' => $stack->hasGitSource() ? $stack->commit_sha : null,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $error = mb_substr($exception->getMessage(), 0, 2000);
            $stack->update(['deploy_status' => 'failed', 'deploy_error' => $error]);
            $deployment->update(['status' => NodeStackDeployment::FAILED, 'error' => $error, 'finished_at' => now()]);
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
     * Starts the build of every service with `build:` at one commit, once for each deploy, and
     * links the builds to the deploy. False while a build is active: the job then ends, and the end
     * of the last build resumes the deploy. A build that failed or was cancelled fails the deploy.
     *
     * @param  Collection<string, NodeWorkload>  $workloads
     */
    private function build(NodeStack $stack, NodeStackDeployment $deployment, Collection $workloads, ?User $requestedBy, string $commit): bool
    {
        $built = $workloads->filter(fn (NodeWorkload $workload): bool => $workload->buildsFromGit());
        if ($built->isEmpty()) {
            $stack->update(['commit_sha' => $commit]);

            return true;
        }
        if ($this->deploymentId === null) {
            // A running build of a service is reused: the service has at most one active build.
            $built->each(function (NodeWorkload $workload) use ($requestedBy, $commit, $deployment): void {
                $build = StartNodeBuild::run($workload, $requestedBy, $commit, skipDeploy: true);
                NodeBuild::query()->whereKey($build->id)->whereNull('node_stack_deployment_id')->update(['node_stack_deployment_id' => $deployment->id]);
            });
        }
        $builds = NodeBuild::query()->where('node_stack_deployment_id', $deployment->id)->with('workload')->get()
            ->keyBy(fn (NodeBuild $build): string => (string) $build->workload?->stack_service);
        if ($builds->contains(fn (NodeBuild $build): bool => ! $build->status->isFinal())) {
            return false;
        }
        $cancelled = $builds->filter(fn (NodeBuild $build): bool => $build->status === NodeBuildStatus::CANCELLED);
        if ($cancelled->isNotEmpty()) {
            throw new RuntimeException('The build of '.$cancelled->keys()->implode(', ').' was cancelled. Nothing was deployed.');
        }
        $failed = $builds->filter(fn (NodeBuild $build): bool => $build->status !== NodeBuildStatus::SUCCEEDED);
        if ($failed->isNotEmpty() || $builds->count() < $built->count()) {
            throw new RuntimeException('The build of '.($failed->isNotEmpty() ? $failed->keys()->implode(', ') : $built->keys()->diff($builds->keys())->implode(', ')).' failed. Nothing was deployed. See the build logs of '.($failed->count() === 1 ? 'the service' : 'each service').'.');
        }
        $stack->update(['commit_sha' => $commit]);

        return true;
    }

    /**
     * Resumes the stack deploy of a build when no other build of that deploy is active. Each end
     * of a build calls it: success, failure, cancel, and a stale build. A second call does nothing.
     */
    public static function resumeAfter(NodeBuild $build): void
    {
        $deploymentId = NodeBuild::query()->whereKey($build->id)->value('node_stack_deployment_id');
        if ($deploymentId === null) {
            return;
        }
        $deployment = NodeStackDeployment::query()->find($deploymentId);
        if ($deployment === null || $deployment->status !== NodeStackDeployment::DEPLOYING
            || NodeBuild::query()->where('node_stack_deployment_id', $deployment->id)->active()->exists()) {
            return;
        }
        self::dispatch($deployment->node_stack_id, $deployment->requested_by_id, $deployment->is_push ? $deployment->commit_sha : null, $deployment->id);
    }

    /** Resumes the deploys whose builds all ended, when the dispatch of the last build was lost. */
    public static function resumeStalled(): void
    {
        NodeStackDeployment::query()
            ->where('status', NodeStackDeployment::DEPLOYING)
            ->whereHas('builds')
            ->whereDoesntHave('builds', fn ($query) => $query->active())
            ->where('updated_at', '<', now()->subMinute())
            ->get()
            ->each(fn (NodeStackDeployment $deployment) => self::dispatch($deployment->node_stack_id, $deployment->requested_by_id, $deployment->is_push ? $deployment->commit_sha : null, $deployment->id));
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
        $error = mb_substr('The stack deployment worker stopped: '.($exception?->getMessage() ?? ''), 0, 2000);
        NodeStack::query()->withoutGlobalScopes()->whereKey($this->stackId)->update(['deploy_status' => 'failed', 'deploy_error' => $error]);
        NodeStackDeployment::query()->where('node_stack_id', $this->stackId)->where('status', NodeStackDeployment::DEPLOYING)
            ->update(['status' => NodeStackDeployment::FAILED, 'error' => $error, 'finished_at' => now()]);
        $stack = NodeStack::query()->withoutGlobalScopes()->find($this->stackId);
        if ($stack !== null) {
            NotifyNodeDeployment::forStack($stack);
        }
    }
}
