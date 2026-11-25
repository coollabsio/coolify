<?php

namespace App\Http\Controllers\Webhook\Concerns;

use App\Models\Application;
use App\Services\DeployTriggers\PullRequestContext;
use App\Services\DeployTriggers\PushContext;
use App\Services\DeployTriggers\PushTriggerRouter;
use App\Services\DeployTriggers\TriggerRoute;
use Illuminate\Support\Collection;

trait RoutesPushTriggers
{
    /**
     * Defers the application to push evaluation when the trigger rules ask for it.
     *
     * @param  Collection<int, array<string, mixed>>  $payloads
     */
    protected function deferPushIfEvaluated(PushTriggerRouter $router, Application $application, Collection $payloads): bool
    {
        if ($router->route($application) !== TriggerRoute::Evaluate) {
            return false;
        }

        $router->defer($application);
        $payloads->push($router->responseEntry($application));

        return true;
    }

    /**
     * A push of a GitLab, Gitea, or Bitbucket webhook.
     *
     * @param  Collection<int, string>|null  $changedFiles
     */
    protected function genericPushContext(string $provider, mixed $repositoryUrl, ?string $branch, ?string $after, ?string $before, ?string $commitMessage, ?Collection $changedFiles): PushContext
    {
        return new PushContext(
            provider: $provider,
            teamId: 0,
            repositoryUrl: is_string($repositoryUrl) ? $repositoryUrl : '',
            ref: 'refs/heads/'.($branch ?? ''),
            after: $after,
            before: $before,
            commitMessage: $commitMessage,
            payloadChangedFiles: $changedFiles?->values()->all(),
        );
    }

    /**
     * A pull or merge request update that changes its code.
     */
    protected function pullRequestPushContext(string $provider, mixed $repositoryUrl, string $ref, ?string $after, PullRequestContext $pullRequest): PushContext
    {
        return new PushContext(
            provider: $provider,
            teamId: 0,
            repositoryUrl: is_string($repositoryUrl) ? $repositoryUrl : '',
            ref: $ref,
            after: $after,
            pullRequest: $pullRequest,
        );
    }

    protected function dispatchPushTriggers(?PushTriggerRouter ...$routers): void
    {
        foreach ($routers as $router) {
            $router?->dispatch();
        }
    }
}
