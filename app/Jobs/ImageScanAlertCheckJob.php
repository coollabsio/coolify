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
 * the wait ends, so the alert is sent even when the check never stores a result.
 */
class ImageScanAlertCheckJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public $uniqueFor = 7200;

    public function __construct(public int $scanId)
    {
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return (string) $this->scanId;
    }

    public function handle(): void
    {
        $scan = ImageScan::query()->find($this->scanId);
        if ($scan instanceof ImageScan) {
            ScanNotifications::reevaluate($scan);
        }
    }
}
