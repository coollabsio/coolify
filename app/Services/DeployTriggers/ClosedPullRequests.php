<?php

namespace App\Services\DeployTriggers;

use App\Models\Application;
use Illuminate\Support\Facades\Cache;

/**
 * Pull requests that closed while an evaluation of one of their pushes may still wait in the queue.
 */
final class ClosedPullRequests
{
    public static function markClosed(Application $application, int $pullRequestId): void
    {
        Cache::put(self::key($application->id, $pullRequestId), true, now()->addDay());
    }

    public static function clear(int $applicationId, int $pullRequestId): void
    {
        Cache::forget(self::key($applicationId, $pullRequestId));
    }

    public static function isClosed(int $applicationId, int $pullRequestId): bool
    {
        return Cache::has(self::key($applicationId, $pullRequestId));
    }

    private static function key(int $applicationId, int $pullRequestId): string
    {
        return "deploy-triggers:pull-request-closed:{$applicationId}:{$pullRequestId}";
    }
}
