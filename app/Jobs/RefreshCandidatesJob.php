<?php

namespace App\Jobs;

use App\Actions\ImageScan\RefreshCandidates;
use App\Events\ImageScanUpdated;
use App\Models\ImageScan;
use App\Services\ImageScan\ScanLockBusy;
use App\Services\ImageScan\ScanNotifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Looks for newer versions of a scanned image after the scan, so deploys never wait for it. The job never sleeps on
 * the scan lock of the server: when it is busy (or a deploy gate waits for it), the job runs again later.
 */
class RefreshCandidatesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = ScanResourceImagesJob::MAX_ATTEMPTS;

    /** An unexpected error fails the job; only a busy lock retries it. */
    public $maxExceptions = 1;

    /** Two candidate scans and the scan of the current base image, plus the registry calls. */
    public $timeout = 7200;

    public $uniqueFor = 7200;

    public $deleteWhenMissingModels = true;

    public function __construct(public ImageScan $scan)
    {
        // Self-hosted Horizon works through 'high' first, so scans there would delay deployments.
        $this->onQueue(isCloud() ? maintenance_queue() : 'default');
    }

    /**
     * Queues the check and marks it as queued (`RefreshCandidates::isQueued()`).
     */
    public static function queueCheck(ImageScan $scan): void
    {
        RefreshCandidates::markQueued($scan, (new self($scan))->uniqueFor);
        self::dispatch($scan);
    }

    public function uniqueId(): string
    {
        return (string) $this->scan->id;
    }

    public function handle(): void
    {
        $scan = $this->scan->fresh();
        // The deploy gate or the daily rescan checked it meanwhile.
        if (! $scan instanceof ImageScan || RefreshCandidates::isFresh($scan)) {
            RefreshCandidates::forgetQueued($this->scan);

            return;
        }

        try {
            RefreshCandidates::run($scan, 0, retryWhenBusy: $this->attempts() < $this->tries);
            RefreshCandidates::forgetQueued($scan);
        } catch (ScanLockBusy) {
            $this->release(ScanResourceImagesJob::lockRetryDelay($this->attempts()));
        }
    }

    /**
     * The check stored no result, so an alert that waits for it is sent now (spec 15.8).
     */
    public function failed(?Throwable $exception): void
    {
        RefreshCandidates::forgetQueued($this->scan);
        $scan = $this->scan->fresh();
        if (! $scan instanceof ImageScan) {
            return;
        }

        ScanNotifications::reevaluate($scan, checkGaveUp: true);
        $resource = $scan->resource;
        if ($resource instanceof Model) {
            $teamId = method_exists($resource, 'team') ? $resource->team()?->id : null;
            ImageScanUpdated::dispatch($teamId, (string) $resource->getAttribute('uuid'), (string) $scan->uuid);
        }
    }
}
