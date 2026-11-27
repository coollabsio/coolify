<?php

namespace App\Services\DeployTriggers;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Pull requests that closed while an evaluation of one of their pushes may still wait in the queue.
 * Their skipped rows keep the closure, so `Deploy anyway` cannot bring the preview back.
 */
final class ClosedPullRequests
{
    public static function markClosed(Application $application, int $pullRequestId): void
    {
        Cache::put(self::key($application->id, $pullRequestId), true, now()->addDay());

        ApplicationDeploymentQueue::query()
            ->where('application_id', $application->id)
            ->where('pull_request_id', $pullRequestId)
            ->where('status', ApplicationDeploymentStatus::SKIPPED->value)
            ->whereNotNull('trigger_reason')
            ->each(fn (ApplicationDeploymentQueue $row) => $row->forceFill(['trigger_reason' => [...$row->trigger_reason, 'pull_request_closed' => true]])->save());
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
