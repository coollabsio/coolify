<?php

namespace App\Http\Controllers\Webhook\Concerns;

use App\Models\Application;
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
}
