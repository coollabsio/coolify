<?php

namespace App\Jobs;

use App\Actions\ImageScan\RefreshCandidates;
use App\Models\ImageScan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Looks for newer versions of a scanned image after the scan, so deploys never wait for it.
 */
class RefreshCandidatesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    /** Two candidate scans with their lock waits and disk checks, plus the registry calls (about 6,400 s). */
    public $timeout = 7200;

    public $uniqueFor = 7200;

    public $deleteWhenMissingModels = true;

    public function __construct(public ImageScan $scan)
    {
        // Self-hosted Horizon works through 'high' first, so scans there would delay deployments.
        $this->onQueue(isCloud() ? maintenance_queue() : 'default');
    }

    public function uniqueId(): string
    {
        return (string) $this->scan->id;
    }

    public function handle(): void
    {
        RefreshCandidates::run($this->scan);
    }
}
