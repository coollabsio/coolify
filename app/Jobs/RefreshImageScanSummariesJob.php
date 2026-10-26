<?php

namespace App\Jobs;

use App\Services\ImageScan\ResourceScans;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Writes the summaries of the current scans of every resource of a team again, after a team-wide ignore changed,
 * so the counts in sidebars and lists match the findings.
 */
class RefreshImageScanSummariesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function __construct(public int $teamId)
    {
        $this->onQueue(isCloud() ? maintenance_queue() : 'default');
    }

    public function handle(): void
    {
        ResourceScans::refreshSummaries(ResourceScans::currentForTeam($this->teamId));
    }
}
