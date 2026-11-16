<?php

namespace App\Http\Controllers\Webhook\Concerns;

use App\Actions\Node\Build\StartNodeBuild;
use App\Actions\Node\DeployNodePreview;
use App\Actions\Node\RemoveNodePreview;
use App\Enums\NodeWorkloadDesiredState;
use App\Jobs\DeployNodeStackJob;
use App\Models\GithubApp;
use App\Models\NodeStack;
use App\Models\NodeWorkload;
use App\Models\Scopes\VisibleThroughAccessGrants;
use App\Support\PullRequestWebhook;
use Closure;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Starts builds of cluster applications from Git push webhooks (decision 0011), and deploys or
 * removes their previews from pull request events (decision 0025). The matching rules and secret
 * checks are the v4 ones. The controller also uses MatchesManualWebhookApplications. A matched
 * service of a stack from Git deploys its whole stack at the pushed commit, once for each stack
 * (decision 0023).
 */
trait StartsClusterWebhookBuilds
{
    /**
     * Builds the cluster applications of the repository and branch whose manual webhook
     * secret of the provider passes the check. A failed check gives the unauthenticated
     * failure payload, so the v4 failure throttling counts it.
     *
     * @param  'github'|'gitlab'|'bitbucket'|'gitea'  $provider
     * @param  Closure(string): bool  $secretMatches
     * @return Collection<int, array<string, mixed>>
     */
    protected function startManualWebhookClusterBuilds(string $provider, string $fullName, string $branch, ?string $commit, bool $skipDeploy, Closure $secretMatches): Collection
    {
        return $this->clusterWebhookWorkloads($fullName, $branch, null)->map(
            fn (NodeWorkload $workload): array => $this->manualClusterWebhookSecretFailure($provider, $workload, $fullName, $secretMatches)
                ?? $this->startClusterWebhookBuild($workload, $commit, $skipDeploy, $provider, 'manual'));
    }

    /**
     * Deploys or removes the previews of the cluster applications of the repository whose manual
     * webhook secret of the provider passes the check. A deploy matches the base branch of the pull
     * request; a close matches each branch, as in v4.
     *
     * @param  'github'|'gitlab'|'bitbucket'|'gitea'  $provider
     * @param  Closure(string): bool  $secretMatches
     * @return Collection<int, array<string, mixed>>
     */
    protected function startManualWebhookClusterPreviews(string $provider, string $fullName, PullRequestWebhook $pullRequest, Closure $secretMatches): Collection
    {
        return $this->clusterWebhookWorkloads($fullName, $pullRequest->closes ? null : $pullRequest->baseBranch, null)->map(
            fn (NodeWorkload $workload): array => $this->manualClusterWebhookSecretFailure($provider, $workload, $fullName, $secretMatches)
                ?? $this->handleClusterPullRequest($workload, $pullRequest, $pullRequest->skipDeploy, $provider, 'manual'));
    }

    /**
     * Deploys or removes the previews of the cluster applications that read the repository through
     * this GitHub App. As in v4, the head commit message can also skip the deploy.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function startGithubAppClusterPreviews(GithubApp $githubApp, string $fullName, PullRequestWebhook $pullRequest): Collection
    {
        $workloads = $this->clusterWebhookWorkloads($fullName, $pullRequest->closes ? null : $pullRequest->baseBranch, $githubApp->id);
        $skipDeploy = $pullRequest->skipDeploy;
        if ($workloads->isNotEmpty() && ! $pullRequest->closes && ! $skipDeploy && $pullRequest->commitSha !== null) {
            [$owner, $repo] = explode('/', $fullName, 2);
            $skipDeploy = self::shouldSkipDeployAny([getGithubCommitMessage($githubApp, $owner, $repo, $pullRequest->commitSha)]);
        }

        return $workloads->map(fn (NodeWorkload $workload): array => $this->handleClusterPullRequest($workload, $pullRequest, $skipDeploy, 'github', 'app'));
    }

    /**
     * Builds the cluster applications that read the repository through this GitHub App.
     * The caller has verified the signature of the App.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function startGithubAppClusterBuilds(GithubApp $githubApp, mixed $fullName, string $branch, ?string $commit, bool $skipDeploy): Collection
    {
        $fullName = $this->manualWebhookRepositoryFullName($fullName);
        if ($fullName === null) {
            return collect();
        }

        return $this->clusterWebhookWorkloads($fullName, $branch, $githubApp->id)
            ->map(fn (NodeWorkload $workload): array => $this->startClusterWebhookBuild($workload, $commit, $skipDeploy, 'github', 'app'));
    }

    /**
     * The response of a manual webhook that matched no v4 application: the cluster
     * payloads, or the unauthenticated response when nothing matched or authorized.
     *
     * @param  Collection<int, array<string, mixed>>  $payloads
     */
    protected function clusterOnlyManualWebhookResponse(Collection $payloads, string $failureKey, string $attempt): Response
    {
        return $payloads->isEmpty()
            ? $this->unauthenticatedManualWebhookResponse($failureKey, $attempt)
            : $this->manualWebhookResponse($payloads, $failureKey, $attempt);
    }

    /**
     * @return Collection<int, NodeWorkload>
     */
    private function clusterWebhookWorkloads(string $fullName, ?string $branch, ?int $githubAppId): Collection
    {
        // A webhook has no actor, so access grants do not apply. The secret or the App decides.
        $query = NodeWorkload::query()
            ->withoutGlobalScope(VisibleThroughAccessGrants::class)
            ->whereNull('database_type')
            // A preview is never a webhook target (decision 0025): events match its parent.
            ->whereNull('preview_of_id');
        if ($branch !== null) {
            $query->where('git_branch', $branch);
        }
        if ($githubAppId !== null) {
            $query->where('github_app_id', $githubAppId);
        }

        return $this->manualWebhookApplications($query, $fullName)
            ->filter(fn (NodeWorkload $workload): bool => $workload->buildsFromGit())
            ->values();
    }

    /**
     * The unauthenticated failure payload when the manual webhook secret of the workload is
     * missing or does not match, else null.
     *
     * @param  Closure(string): bool  $secretMatches
     * @return ?array<string, mixed>
     */
    private function manualClusterWebhookSecretFailure(string $provider, NodeWorkload $workload, string $fullName, Closure $secretMatches): ?array
    {
        $secret = $workload->{"manual_webhook_secret_{$provider}"};
        if (filled($secret) && $secretMatches($secret)) {
            return null;
        }
        auditLogWebhookFailure($provider, blank($secret) ? 'webhook_secret_missing' : 'invalid_signature', [
            'workload_uuid' => $workload->uuid,
            'workload_name' => $workload->name,
            'repository' => $fullName,
            'mode' => 'manual',
        ]);

        return $this->unauthenticatedManualWebhookFailurePayload();
    }

    /**
     * A close removes the preview also when previews are off. A deploy follows the settings of
     * the parent and the fork checks of the provider (decision 0025).
     *
     * @return array<string, mixed>
     */
    private function handleClusterPullRequest(NodeWorkload $parent, PullRequestWebhook $pullRequest, bool $skipDeploy, string $provider, string $mode): array
    {
        $result = ['application_uuid' => $parent->uuid, 'application_name' => $parent->name, 'pull_request_id' => $pullRequest->number];
        $context = ['provider' => $provider, 'mode' => $mode, 'workload_uuid' => $parent->uuid, 'pull_request_id' => $pullRequest->number];
        if ($pullRequest->closes) {
            $preview = $parent->previews()->withoutGlobalScopes()->where('pull_request_id', $pullRequest->number)->first();
            if ($preview === null) {
                return [...$result, 'status' => 'skipped', 'message' => 'This pull request has no preview.'];
            }
            if ($preview->desired_state === NodeWorkloadDesiredState::REMOVED) {
                return [...$result, 'status' => 'skipped', 'message' => 'The preview is already being removed.', 'preview_uuid' => $preview->uuid];
            }
            RemoveNodePreview::run($preview);
            auditLog('webhook.preview.removed', [...$context, 'preview_uuid' => $preview->uuid]);

            return [...$result, 'status' => 'success', 'message' => 'Preview removed.', 'preview_uuid' => $preview->uuid];
        }

        $skipReason = match (true) {
            ! $parent->canHavePreviews() => 'Only a Git application can have previews.',
            ! $parent->preview_deployments_enabled => 'Preview deployments are off for this application.',
            $pullRequest->refusal !== null => $pullRequest->refusal,
            $pullRequest->fromFork && ! $parent->fork_preview_deployments_enabled => 'The pull request is from a fork, and preview deployments of forks are off for this application.',
            $skipDeploy => 'The pull request title or its head commit contains [skip cd] or [skip ci].',
            default => null,
        };
        if ($skipReason !== null) {
            return [...$result, 'status' => 'skipped', 'message' => $skipReason];
        }

        try {
            ['preview' => $preview, 'build' => $build, 'domain_error' => $domainError] = DeployNodePreview::run(
                $parent, $pullRequest->number, $pullRequest->commitSha, $pullRequest->ref, $pullRequest->url, $pullRequest->fromFork,
            );
        } catch (Throwable $exception) {
            return [...$result, 'status' => 'failed', 'message' => $exception->getMessage()];
        }
        $result = [...$result, 'preview_uuid' => $preview->uuid, 'build_uuid' => $build->uuid];
        if (! $build->wasRecentlyCreated) {
            return [...$result, 'status' => 'skipped', 'message' => 'A build of this commit is already running.'];
        }
        auditLog('webhook.preview.queued', [...$context, 'preview_uuid' => $preview->uuid, 'build_uuid' => $build->uuid, 'commit' => $pullRequest->commitSha, 'from_fork' => $pullRequest->fromFork]);

        return [...$result, 'status' => 'success', 'message' => 'Preview build queued.', ...($domainError === null ? [] : ['domain_error' => $domainError])];
    }

    /**
     * @return array<string, mixed>
     */
    private function startClusterWebhookBuild(NodeWorkload $workload, ?string $commit, bool $skipDeploy, string $provider, string $mode): array
    {
        $result = ['application_uuid' => $workload->uuid, 'application_name' => $workload->name];
        if ($skipDeploy) {
            return [...$result, 'status' => 'skipped', 'message' => 'All commits contain [skip cd] or [skip ci]. Skipping deployment.'];
        }
        if (! $workload->nodes()->exists()) {
            return [...$result, 'status' => 'failed', 'message' => 'This application is not placed on any server.'];
        }

        if ($workload->node_stack_id !== null) {
            return $this->startClusterWebhookStackDeploy($workload, $commit, $provider, $mode);
        }

        if (! $workload->is_auto_deploy_enabled) {
            return [...$result, 'status' => 'skipped', 'message' => 'Deployments disabled.'];
        }

        try {
            $build = StartNodeBuild::run($workload, null, $commit, fromWebhook: true);
        } catch (Throwable $exception) {
            return [...$result, 'status' => 'failed', 'message' => $exception->getMessage()];
        }
        if (! $build->wasRecentlyCreated) {
            return [...$result, 'status' => 'skipped', 'message' => 'A build of this commit is already queued or running.', 'build_uuid' => $build->uuid];
        }

        auditLog('webhook.build.queued', [
            'provider' => $provider,
            'mode' => $mode,
            'workload_uuid' => $workload->uuid,
            'workload_name' => $workload->name,
            'build_uuid' => $build->uuid,
            'commit' => $commit,
        ]);

        return [...$result, 'status' => 'success', 'message' => $build->is_waiting ? 'Build queued. It starts when the running build ends.' : 'Build queued.', 'build_uuid' => $build->uuid];
    }

    /**
     * Queues one deploy of the stack of the service. The other matched services of the stack
     * find it queued and are skipped.
     *
     * @return array<string, mixed>
     */
    private function startClusterWebhookStackDeploy(NodeWorkload $workload, ?string $commit, string $provider, string $mode): array
    {
        $stack = NodeStack::query()->withoutGlobalScopes()->find($workload->node_stack_id);
        if ($stack === null) {
            return ['application_uuid' => $workload->uuid, 'application_name' => $workload->name, 'status' => 'failed', 'message' => 'The stack of this application does not exist.'];
        }
        $result = ['stack_uuid' => $stack->uuid, 'stack_name' => $stack->name];
        $queued = NodeStack::query()->withoutGlobalScopes()
            ->whereKey($stack->id)
            ->where(fn ($query) => $query->whereNull('deploy_status')->orWhereNotIn('deploy_status', ['queued', 'deploying']))
            ->update(['deploy_status' => 'queued', 'deploy_error' => null]);
        if ($queued === 0) {
            return [...$result, 'status' => 'skipped', 'message' => 'A deployment of this stack is already queued or running.'];
        }
        DeployNodeStackJob::dispatch($stack->id, null, $commit);
        auditLog('webhook.stack_deploy.queued', [
            'provider' => $provider,
            'mode' => $mode,
            'stack_uuid' => $stack->uuid,
            'stack_name' => $stack->name,
            'workload_uuid' => $workload->uuid,
            'commit' => $commit,
        ]);

        return [...$result, 'status' => 'success', 'message' => 'Stack deployment queued. Each service with build: builds first.'];
    }
}
