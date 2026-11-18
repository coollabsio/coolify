<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\BaseModel;
use App\Notifications\Application\StatusChanged;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Sends the "resource stopped" notification when a status check finds that a resource stopped
 * without a stop from Coolify (for example a crash or `docker kill`).
 *
 * The job runs after a delay and checks the resource again. A resource that is up again by then
 * (Docker restarted it, or a deployment replaced its container) sends no notification.
 */
class NotifyResourceStoppedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CONFIRMATION_DELAY_SECONDS = 120;

    /**
     * How long a stop that Coolify starts sends no notification, in addition to the stop timeout.
     */
    private const EXPECTED_STOP_SECONDS = 300;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    public function __construct(public BaseModel $resource)
    {
        $this->onQueue('high');
        $this->delay(now()->addSeconds(self::CONFIRMATION_DELAY_SECONDS));
    }

    /**
     * Call before Coolify stops or recreates the containers of a resource, so that the stop sends no notification.
     */
    public static function expectStop(BaseModel $resource, int $stopTimeoutSeconds = 30): void
    {
        Cache::put(self::expectedStopKey($resource), true, now()->addSeconds($stopTimeoutSeconds + self::EXPECTED_STOP_SECONDS));
    }

    /**
     * Read which resources are up, before a status check changes their status.
     *
     * @param  iterable<BaseModel>  $resources
     * @return array<class-string<BaseModel>, list<int>>
     */
    public static function upResourceIds(iterable $resources): array
    {
        $ids = [];
        foreach ($resources as $resource) {
            if (filled($resource->status) && ! self::isExited($resource->status)) {
                $ids[$resource::class][] = $resource->getKey();
            }
        }

        return $ids;
    }

    /**
     * After a status check: queue a notification for each resource that was up before the check and is exited now.
     *
     * @param  array<class-string<BaseModel>, list<int>>  $upResourceIds  from upResourceIds()
     */
    public static function dispatchForStopped(array $upResourceIds): void
    {
        foreach ($upResourceIds as $class => $ids) {
            $class::query()
                ->whereKey($ids)
                ->where('status', 'like', 'exited%')
                ->get()
                ->reject(fn (BaseModel $resource): bool => self::stopIsExpected($resource))
                ->each(fn (BaseModel $resource) => self::dispatch($resource));
        }
    }

    public function handle(): void
    {
        $resource = $this->resource::query()->whereKey($this->resource->getKey())->first();
        if ($resource === null
            || ! self::isExited($resource->status)
            || (bool) $resource->restart_limit_reached
            || self::stopIsExpected($resource)
            || self::isDeploying($resource)) {
            return;
        }

        $team = $resource instanceof ApplicationPreview ? $resource->application?->team() : $resource->team();
        $team?->notify(new StatusChanged($resource));
    }

    private static function isExited(?string $status): bool
    {
        return str($status)->startsWith('exited');
    }

    private static function stopIsExpected(BaseModel $resource): bool
    {
        return Cache::has(self::expectedStopKey($resource));
    }

    private static function expectedStopKey(BaseModel $resource): string
    {
        return 'resource-stop-expected:'.$resource->getMorphClass().':'.$resource->getKey();
    }

    private static function isDeploying(BaseModel $resource): bool
    {
        [$applicationId, $pullRequestId] = match (true) {
            $resource instanceof Application => [$resource->id, 0],
            $resource instanceof ApplicationPreview => [$resource->application_id, $resource->pull_request_id],
            default => [null, null],
        };
        if ($applicationId === null) {
            return false;
        }

        return ApplicationDeploymentQueue::query()
            ->where('application_id', (string) $applicationId)
            ->where('pull_request_id', $pullRequestId)
            ->whereIn('status', [ApplicationDeploymentStatus::QUEUED->value, ApplicationDeploymentStatus::IN_PROGRESS->value])
            ->exists();
    }
}
