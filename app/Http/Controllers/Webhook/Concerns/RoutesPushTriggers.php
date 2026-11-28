<?php

namespace App\Http\Controllers\Webhook\Concerns;

use App\Models\Application;
use App\Services\DeployTriggers\PullRequestContext;
use App\Services\DeployTriggers\PushContext;
use App\Services\DeployTriggers\PushTriggerRouter;
use App\Services\DeployTriggers\TriggerRoute;
use App\Services\ProjectConfig\ConfigPushRouter;
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

        // A push that changes coolify.json holds managed applications until the file is applied, as queue_application_deployment() does.
        if (! $router->isPullRequest() && app(ConfigPushRouter::class)->hold($application)) {
            $payloads->push([
                'application' => $application->name,
                'status' => 'held',
                'message' => 'The deployment waits until coolify.json of this push is applied.',
                'application_uuid' => $application->uuid,
            ]);

            return true;
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
