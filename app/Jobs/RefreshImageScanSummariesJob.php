<?php

namespace App\Jobs;

use App\Services\ImageScan\ResourceScans;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Writes the summaries of the current scans of every resource of a team again, with their stored security status, after
 * a team-wide ignore was added or removed, a team-wide ignore ended, or the upgrade to security statuses, so the status
 * in sidebars, markers, and lists matches the findings.
 */
class RefreshImageScanSummariesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    /** With a server, only the resources that have scans on that server, for example after its rescan days changed. */
    public function __construct(public int $teamId, public ?int $serverId = null)
    {
        $this->onQueue(isCloud() ? maintenance_queue() : 'default');
    }

    public function handle(): void
    {
        ResourceScans::refreshSummaries(ResourceScans::currentForTeam($this->teamId, $this->serverId));
    }
}
