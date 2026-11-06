<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\GithubApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mirrors the status of a Coolify deployment to a GitHub Deployment, so GitHub shows the
 * branch or pull request as deployed. Each run sends the current status, so a late or
 * repeated run converges to the same result.
 */
class SyncGithubDeploymentJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * API errors are tried three times. Waits for the per-deployment lock are not counted, so a
     * terminal sync that waits behind a slow sync still runs (see retryUntil()).
     */
    public $maxExceptions = 3;

    public $backoff = 10;

    /**
     * Seconds that one request waits for GitHub. A slow GitHub must not hold a queue worker.
     */
    public const HTTP_TIMEOUT_SECONDS = 10;

    /**
     * Longer than the worst case of all requests with their retries, shorter than the lock
     * expiry, so the per-deployment lock is held while the job runs.
     */
    public $timeout = 100;

    private const LOCK_EXPIRES_AFTER = 120;

    private const LOCK_RELEASE_DELAY = 5;

    public function __construct(public int $deploymentQueueId, public bool $deactivate = false)
    {
        // Not the deployment queue: a slow GitHub must not delay deployments.
        $this->onQueue('default');
    }

    /**
     * Queue a sync when the deployment belongs to a GitHub App that can write deployments.
     */
    public static function dispatchFor(ApplicationDeploymentQueue $deployment): void
    {
        if (filled($deployment->parent_deployment_uuid)) {
            // Deployments to additional servers are part of the main deployment.
            return;
        }

        // A soft-deleted application still needs its last status on GitHub.
        $source = Application::withTrashed()->find($deployment->application_id)?->source;
        if (! $source instanceof GithubApp || ! $source->canSyncDeployments()) {
            return;
        }

        self::dispatch($deployment->id);
    }

    /**
     * Mark the GitHub Deployments of a closed pull request as inactive. This also covers
     * deployments that GitHub does not know yet, so a late sync cannot create one.
     */
    public static function deactivatePullRequest(Application $application, int $pullRequestId): void
    {
        ApplicationDeploymentQueue::query()
            ->where('application_id', $application->id)
            ->where('pull_request_id', $pullRequestId)
            ->where(fn ($query) => $query->whereNull('github_deployment_state')->orWhere('github_deployment_state', '!=', 'inactive'))
            ->pluck('id')
            ->each(fn (int $deploymentQueueId) => self::dispatch($deploymentQueueId, deactivate: true));
    }

    /**
     * All syncs of one deployment share this lock, and handle() reads the state while it
     * holds the lock. Thus a deactivation cannot happen between that read and the requests.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("github-deployment:{$this->deploymentQueueId}"))->releaseAfter(self::LOCK_RELEASE_DELAY)->expireAfter(self::LOCK_EXPIRES_AFTER)];
    }

    /**
     * A sync can wait for a lock that is held up to its expiry. Fixed at dispatch.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addSeconds(self::LOCK_EXPIRES_AFTER + 60);
    }

    public function handle(): void
    {
        $deployment = ApplicationDeploymentQueue::find($this->deploymentQueueId);
        $application = $deployment ? Application::withTrashed()->find($deployment->application_id) : null;
        $source = $application?->source;
        if (! $source instanceof GithubApp || ! $source->canSyncDeployments()) {
            return;
        }

        // Inactive is the end state: a closed pull request must not show as deployed again.
        if ($deployment->github_deployment_state === 'inactive') {
            return;
        }

        $state = $this->deactivate ? 'inactive' : $this->githubState($deployment->status);
        if ($state === null || $state === $deployment->github_deployment_state) {
            return;
        }

        $repository = gitRepositorySlug($application->git_repository);
        $githubDeploymentId = $deployment->github_deployment_id;
        if (! $githubDeploymentId) {
            if ($state === 'inactive') {
                // Keep the marker, so a late sync does not create a GitHub Deployment.
                ApplicationDeploymentQueue::whereKey($deployment->id)->update(['github_deployment_state' => 'inactive']);

                return;
            }
            // The deployment ended before GitHub knew about it. The deployment job sets
            // horizon_job_worker together with IN_PROGRESS, so a failure without it never started.
            if ($state === 'error' || ($state === 'failure' && blank($deployment->horizon_job_worker))) {
                return;
            }
            $githubDeploymentId = $this->createGithubDeployment($deployment, $application, $source, $repository);
            ApplicationDeploymentQueue::whereKey($deployment->id)->update(['github_deployment_id' => $githubDeploymentId]);
        }

        githubApi($source, "repos/{$repository}/deployments/{$githubDeploymentId}/statuses", 'post', array_filter([
            'state' => $state,
            'description' => $this->description($state),
            'log_url' => $this->logUrl($deployment, $application),
            'environment_url' => $state === 'success' ? $this->environmentUrl($deployment, $application) : null,
        ]), timeout: self::HTTP_TIMEOUT_SECONDS);

        ApplicationDeploymentQueue::whereKey($deployment->id)->update(['github_deployment_state' => $state]);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Failed to sync the GitHub deployment.', [
            'deployment_queue_id' => $this->deploymentQueueId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function githubState(string $status): ?string
    {
        return match ($status) {
            ApplicationDeploymentStatus::IN_PROGRESS->value => 'in_progress',
            ApplicationDeploymentStatus::FINISHED->value => 'success',
            ApplicationDeploymentStatus::FAILED->value => 'failure',
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value => 'error',
            default => null,
        };
    }

    private function createGithubDeployment(ApplicationDeploymentQueue $deployment, Application $application, GithubApp $source, string $repository): int
    {
        $isPreview = $deployment->pull_request_id !== 0;
        $environment = $isPreview ? "{$application->name} (preview)" : $application->name;

        $response = githubApi($source, "repos/{$repository}/deployments", 'post', [
            'ref' => $this->ref($deployment, $application, $source, $repository),
            'environment' => Str::limit($environment, 255, ''),
            'description' => Str::limit($isPreview ? "Coolify preview for PR #{$deployment->pull_request_id}" : 'Coolify deployment', 140, ''),
            // GitHub would merge the default branch into the ref, or wait for all commit checks.
            'auto_merge' => false,
            'required_contexts' => [],
            'transient_environment' => $isPreview,
            'production_environment' => ! $isPreview,
        ], timeout: self::HTTP_TIMEOUT_SECONDS);

        return (int) $response['data']->get('id');
    }

    /**
     * GitHub links a deployment to a pull request only when its ref is the head branch. A fork
     * branch is not in the base repository, so a fork preview uses the head commit. GitHub
     * resolves a branch to its current commit, so the branch is used only while it still
     * points to the deployed commit.
     */
    private function ref(ApplicationDeploymentQueue $deployment, Application $application, GithubApp $source, string $repository): string
    {
        $commit = filled($deployment->commit) && $deployment->commit !== 'HEAD' ? $deployment->commit : null;

        if ($deployment->pull_request_id !== 0) {
            $pullRequest = githubApi($source, "repos/{$repository}/pulls/{$deployment->pull_request_id}", timeout: self::HTTP_TIMEOUT_SECONDS)['data'];
            $headRepository = (string) data_get($pullRequest, 'head.repo.full_name', '');
            $baseRepository = (string) data_get($pullRequest, 'base.repo.full_name', $repository);
            $headBranch = data_get($pullRequest, 'head.ref');
            $headCommit = (string) data_get($pullRequest, 'head.sha');
            $isDeployedCommit = $commit === null || strcasecmp($commit, $headCommit) === 0;
            if (filled($headBranch) && strcasecmp($headRepository, $baseRepository) === 0 && $isDeployedCommit) {
                return $headBranch;
            }

            return $commit ?? $headCommit;
        }

        // The branch can move past the deployed commit before the GitHub deployment is created.
        return $commit ?? $application->git_branch;
    }

    private function description(string $state): string
    {
        return match ($state) {
            'in_progress' => 'Deploying with Coolify.',
            'success' => 'Deployed with Coolify.',
            'failure' => 'The Coolify deployment failed.',
            'error' => 'The Coolify deployment was cancelled.',
            default => 'The pull request was closed.',
        };
    }

    private function logUrl(ApplicationDeploymentQueue $deployment, Application $application): string
    {
        return base_url()."/project/{$application->environment->project->uuid}/environment/{$application->environment->uuid}/application/{$application->uuid}/deployment/{$deployment->deployment_uuid}";
    }

    private function environmentUrl(ApplicationDeploymentQueue $deployment, Application $application): ?string
    {
        if ($deployment->pull_request_id !== 0) {
            $preview = $application->previews()->where('pull_request_id', $deployment->pull_request_id)->first();
            $domains = $application->build_pack === 'dockercompose'
                ? collect(json_decode((string) $preview?->docker_compose_domains, true) ?: [])->pluck('domain')->filter()->first()
                : $preview?->fqdn;
        } else {
            $domains = $application->build_pack === 'dockercompose'
                ? collect(json_decode((string) $application->docker_compose_domains, true) ?: [])->pluck('domain')->filter()->first()
                : $application->fqdn;
        }

        $url = trim((string) str($domains)->explode(',')->first());

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://') ? $url : null;
    }
}
