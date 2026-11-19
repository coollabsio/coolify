<?php

namespace App\Jobs;

use App\Models\ImageScan;
use App\Services\ImageScan\ScanNotifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Looks again at the alert of a scan whose exploited problems waited for the version check (spec 15.8). It runs when
 * the wait ends, so the alert is sent even when the check never stores a result. Unique per scan and scan time, so a
 * rescan of the same row during a wait gets its own run.
 */
class ImageScanAlertCheckJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 3;

    public $uniqueFor = 7200;

    /**
     * @param  ?string  $scannedAt  The scan time of the row when the wait started.
     */
    public function __construct(public int $scanId, public ?string $scannedAt = null)
    {
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return $this->scanId.':'.$this->scannedAt;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $scan = ImageScan::query()->find($this->scanId);
        if ($scan instanceof ImageScan) {
            // Errors fail the run, so it is tried again.
            ScanNotifications::afterScan($scan, candidateCheck: true);
        }
    }
}
