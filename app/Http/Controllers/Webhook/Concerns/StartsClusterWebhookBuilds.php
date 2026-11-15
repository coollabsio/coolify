<?php

namespace App\Http\Controllers\Webhook\Concerns;

use App\Actions\Node\Build\StartNodeBuild;
use App\Jobs\DeployNodeStackJob;
use App\Models\GithubApp;
use App\Models\NodeStack;
use App\Models\NodeWorkload;
use App\Models\Scopes\VisibleThroughAccessGrants;
use Closure;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Starts builds of cluster applications from Git push webhooks (decision 0011). The
 * matching rules and secret checks are the v4 ones. Pull request events do not build
 * cluster applications yet. The controller also uses MatchesManualWebhookApplications.
 * A matched service of a stack from Git deploys its whole stack at the pushed commit, once
 * for each stack (decision 0023).
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
        return $this->clusterWebhookWorkloads($fullName, $branch, null)->map(function (NodeWorkload $workload) use ($provider, $fullName, $commit, $skipDeploy, $secretMatches): array {
            $secret = $workload->{"manual_webhook_secret_{$provider}"};
            if (blank($secret) || ! $secretMatches($secret)) {
                auditLogWebhookFailure($provider, blank($secret) ? 'webhook_secret_missing' : 'invalid_signature', [
                    'workload_uuid' => $workload->uuid,
                    'workload_name' => $workload->name,
                    'repository' => $fullName,
                    'mode' => 'manual',
                ]);

                return $this->unauthenticatedManualWebhookFailurePayload();
            }

            return $this->startClusterWebhookBuild($workload, $commit, $skipDeploy, $provider, 'manual');
        });
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
    private function clusterWebhookWorkloads(string $fullName, string $branch, ?int $githubAppId): Collection
    {
        // A webhook has no actor, so access grants do not apply. The secret or the App decides.
        $query = NodeWorkload::query()
            ->withoutGlobalScope(VisibleThroughAccessGrants::class)
            ->whereNull('database_type')
            // A push never deploys a preview (decision 0025); pull request events do.
            ->whereNull('preview_of_id')
            ->where('git_branch', $branch);
        if ($githubAppId !== null) {
            $query->where('github_app_id', $githubAppId);
        }

        return $this->manualWebhookApplications($query, $fullName)
            ->filter(fn (NodeWorkload $workload): bool => $workload->buildsFromGit())
            ->values();
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

        try {
            $build = StartNodeBuild::run($workload, null, $commit);
        } catch (Throwable $exception) {
            return [...$result, 'status' => 'failed', 'message' => $exception->getMessage()];
        }
        if (! $build->wasRecentlyCreated) {
            return [...$result, 'status' => 'skipped', 'message' => 'A build of this application is already running.', 'build_uuid' => $build->uuid];
        }

        auditLog('webhook.build.queued', [
            'provider' => $provider,
            'mode' => $mode,
            'workload_uuid' => $workload->uuid,
            'workload_name' => $workload->name,
            'build_uuid' => $build->uuid,
            'commit' => $commit,
        ]);

        return [...$result, 'status' => 'success', 'message' => 'Build queued.', 'build_uuid' => $build->uuid];
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
