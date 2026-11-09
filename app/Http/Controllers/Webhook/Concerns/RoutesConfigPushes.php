<?php

namespace App\Http\Controllers\Webhook\Concerns;

use App\Jobs\CommentConfigPlanJob;
use App\Models\EnvironmentConfigLink;
use App\Models\GithubApp;
use App\Services\ProjectConfig\ConfigPushRouter;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Sends a push to the environments that follow the branch with coolify.json.
 */
trait RoutesConfigPushes
{
    /**
     * Plans the file of a same-repository pull request for each environment that follows its base branch.
     */
    protected function commentConfigPlans(GithubApp $source, string $fullName, string $baseBranch, int $pullRequestId, string $headSha): void
    {
        if ($pullRequestId <= 0 || ! preg_match('/^[0-9a-f]{40}$/i', $headSha)) {
            return;
        }
        EnvironmentConfigLink::query()
            ->where('mode', EnvironmentConfigLink::MODE_GIT)
            ->where('branch', $baseBranch)
            ->where('source_type', GithubApp::class)
            ->where('source_id', $source->id)
            ->whereNull('suspended_at')
            ->get()
            ->filter(fn (EnvironmentConfigLink $link): bool => $this->manualWebhookRepositoryMatches($link->repository, $fullName))
            ->each(fn (EnvironmentConfigLink $link) => CommentConfigPlanJob::dispatch($link, $pullRequestId, $headSha));
    }

    /**
     * @param  Closure(string): bool|null  $verifySecret  For manual webhooks: checks the signature with a link secret.
     */
    protected function beginConfigPush(?Model $source, mixed $fullName, ?string $branch, ?string $commit, ?Closure $verifySecret = null): void
    {
        $fullName = $this->manualWebhookRepositoryFullName($fullName);
        if ($fullName === null || blank($branch)) {
            return;
        }

        app(ConfigPushRouter::class)->beginPush(
            $source,
            fn (?string $repository): bool => $this->manualWebhookRepositoryMatches($repository, $fullName),
            $branch,
            $commit,
            $verifySecret,
        );
    }
}
