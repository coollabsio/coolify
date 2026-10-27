<?php

namespace App\Jobs;

use App\Actions\ImageScan\ScanImage;
use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ImageScan;
use App\Models\Server;
use App\Models\Service;
use App\Services\ImageScan\ImageScanPolicyResolver;
use App\Services\ImageScan\ResourceScans;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Scans the images of a resource one at a time, after it started, so the scan is not in the critical path of a
 * deploy (spec 4.4).
 */
class ScanResourceImagesJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Waits before the next attempt when the scan lock of the server is busy or a deploy gate waits for it. */
    public const LOCK_RETRY_DELAYS = [60, 120, 300];

    /** Attempts of a job, for a busy lock and for an image that is still being pulled. */
    public const MAX_ATTEMPTS = 10;

    /** Images scanned when the job derives them from the resource. */
    public const MAX_DERIVED_IMAGES = 5;

    /** A service or database scan waits this long after the start was queued, so the image is pulled first. */
    public const START_DELAY_SECONDS = 30;

    /** Attempts of a job after a start: later ones wait for an image that is still being pulled. */
    public const START_MAX_ATTEMPTS = 3;

    public const START_RETRY_DELAY_SECONDS = 120;

    public $tries = self::MAX_ATTEMPTS;

    /** An unexpected error fails the job; only a busy lock or a missing image retry it. */
    public $maxExceptions = 1;

    public $timeout;

    /**
     * @param  list<string>  $imageRefs  Empty: the images of the resource (imageRefsFor()).
     * @param  bool  $retryWhenImageMissing  A job after a service or database start retries an image that is not pulled yet.
     * @param  ?string  $baseImageRef  The base image of the Dockerfile build of the application.
     */
    public function __construct(public Model $resource, public ?int $deploymentQueueId = null, public array $imageRefs = [], public bool $retryWhenImageMissing = false, public ?string $baseImageRef = null)
    {
        // Self-hosted Horizon works through 'high' first, so scans there would delay deployments.
        $this->onQueue(isCloud() ? maintenance_queue() : 'default');
        $images = $imageRefs === [] ? $this->derivedImageCount() : count($imageRefs);
        $this->timeout = $images * ScanImage::LOCK_TTL;
    }

    /**
     * Queues the scan of a service or standalone database that was started. Never blocks or fails the start.
     */
    public static function dispatchAfterStart(Model $resource): void
    {
        try {
            if (! ImageScanPolicyResolver::isScanOn($resource)) {
                return;
            }

            self::dispatch($resource, null, [], true)->delay(now()->addSeconds(self::START_DELAY_SECONDS));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function handle(): void
    {
        $deployment = $this->deployment();
        $server = $this->server($deployment);
        if (! $server instanceof Server) {
            return;
        }

        $done = $this->doneTargets();
        $missing = [];
        $lastAttempt = $this->attempts() >= $this->tries;
        foreach ($this->scanTargets() as [$resource, $imageRef]) {
            $key = $this->targetKey($resource, $imageRef);
            if (in_array($key, $done, true)) {
                continue;
            }

            $baseImageRef = $resource === $this->resource && $resource instanceof Application ? $this->baseImageRef : null;
            // Never sleeps on the server lock: a deploy gate may need it. A busy lock retries the job later.
            $scan = ScanImage::run($server, $resource, $imageRef, $deployment, 0, $baseImageRef, retryWhenBusy: ! $lastAttempt);

            if (! $lastAttempt && ScanImage::lockWasBusy($scan)) {
                $this->rememberDoneTargets($done);
                $this->release(self::lockRetryDelay($this->attempts()));

                return;
            }

            if ($this->shouldRetry($scan)) {
                // Only the last attempt leaves the error visible.
                Cache::forget(ScanImage::lastErrorCacheKey($resource));
                $missing[] = $key;
            } else {
                $done[] = $key;
            }
        }

        if ($missing !== []) {
            $this->rememberDoneTargets($done);
            $this->release(self::START_RETRY_DELAY_SECONDS);
        }
    }

    /**
     * The wait before attempt $attempt + 1 after a busy lock: 60, 120, then 300 seconds.
     */
    public static function lockRetryDelay(int $attempt): int
    {
        return self::LOCK_RETRY_DELAYS[min(max($attempt, 1), count(self::LOCK_RETRY_DELAYS)) - 1];
    }

    private function shouldRetry(ImageScan $scan): bool
    {
        return $this->retryWhenImageMissing
            && $this->attempts() < min(self::START_MAX_ATTEMPTS, $this->tries)
            && $scan->status === ImageScanStatus::Failed
            && $scan->error_code === ImageScanErrorCode::ImageMissing;
    }

    private function targetKey(Model $resource, string $imageRef): string
    {
        return $resource->getMorphClass().':'.$resource->getKey().':'.$imageRef;
    }

    /**
     * A released job runs again from its stored payload, so the targets of earlier attempts are kept in the cache.
     *
     * @return list<string>
     */
    private function doneTargets(): array
    {
        $uuid = $this->job?->uuid();

        return $uuid === null ? [] : (array) Cache::get("image-scan:start-done:{$uuid}", []);
    }

    /**
     * @param  list<string>  $done
     */
    private function rememberDoneTargets(array $done): void
    {
        $uuid = $this->job?->uuid();
        if ($uuid !== null) {
            Cache::put("image-scan:start-done:{$uuid}", $done, now()->addHours(2));
        }
    }

    /**
     * The images a resource runs. Applications: the images of their latest completed scans. Services: the images of
     * their applications and databases. Standalone databases: their image.
     *
     * @return list<string>
     */
    public function imageRefsFor(Model $resource): array
    {
        if ($resource instanceof Application) {
            return ImageScan::query()
                ->where('resource_type', $resource->getMorphClass())
                ->where('resource_id', $resource->getKey())
                ->where('status', ImageScanStatus::Completed)
                ->orderByDesc('scanned_at')
                ->orderByDesc('id')
                ->pluck('image_ref')
                ->unique()
                ->take(self::MAX_DERIVED_IMAGES)
                ->values()
                ->all();
        }

        return array_map(fn (array $target): string => $target[1], $this->scanTargetsFor($resource));
    }

    /**
     * Each image with the resource its result belongs to. A service keeps results per application and database.
     *
     * @return list<array{0: Model, 1: string}>
     */
    private function scanTargets(): array
    {
        if ($this->imageRefs !== []) {
            $targets = array_map(fn ($imageRef): array => [$this->resource, $imageRef], $this->imageRefs);
        } elseif ($this->resource instanceof Application) {
            $targets = array_map(fn (string $imageRef): array => [$this->resource, $imageRef], $this->imageRefsFor($this->resource));
        } else {
            $targets = $this->scanTargetsFor($this->resource);
        }

        $seen = [];

        return array_values(array_filter($targets, function (array $target) use (&$seen): bool {
            [$resource, $imageRef] = $target;
            if (! is_string($imageRef)) {
                return false;
            }
            $key = $this->targetKey($resource, $imageRef);
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        }));
    }

    /**
     * @return list<array{0: Model, 1: string}>
     */
    private function scanTargetsFor(Model $resource): array
    {
        return ResourceScans::scanTargets($resource);
    }

    private function derivedImageCount(): int
    {
        if ($this->resource instanceof Service) {
            return max(self::MAX_DERIVED_IMAGES, $this->resource->applications()->count() + $this->resource->databases()->count());
        }

        return self::MAX_DERIVED_IMAGES;
    }

    private function deployment(): ?ApplicationDeploymentQueue
    {
        if ($this->deploymentQueueId === null || ! $this->resource instanceof Application) {
            return null;
        }

        return ApplicationDeploymentQueue::query()
            ->whereKey($this->deploymentQueueId)
            ->where('application_id', (string) $this->resource->getKey())
            ->first();
    }

    /**
     * The server of the deployment (an additional server runs its own copy of the image), else the server the
     * scan policy of the resource uses.
     */
    private function server(?ApplicationDeploymentQueue $deployment): ?Server
    {
        $server = $deployment?->server ?? ImageScanPolicyResolver::mainServerOf($this->resource);

        return $server instanceof Server ? $server : null;
    }
}
