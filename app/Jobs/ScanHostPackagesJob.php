<?php

namespace App\Jobs;

use App\Actions\ImageScan\ScanHostPackages;
use App\Enums\ImageScanErrorCode;
use App\Events\ServerPatchCheckFinished;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Scans the OS packages of a server. A busy image scan lock runs the job again later; after the last try it records
 * `LockBusy`. When it ends, the patches page reloads through `ServerPatchCheckFinished`.
 *
 * The unique lock ends when the job starts, so a scan requested during a running scan (for example, after a package
 * update) is queued and runs after it, instead of being dropped.
 */
class ScanHostPackagesJob implements ShouldBeEncrypted, ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const RETRY_DELAY_SECONDS = 300;

    public $tries = 6;

    public $maxExceptions = 1;

    public $timeout = 2400;

    public $uniqueFor = 3600;

    public function __construct(public Server $server)
    {
        // Self-hosted Horizon works through 'high' first, so scans there would delay deployments.
        $this->onQueue(isCloud() ? maintenance_queue() : 'default');
    }

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    public static function runningCacheKey(Server $server): string
    {
        return "server-host-scan-running:{$server->id}";
    }

    /**
     * Queues a scan and marks it as running, so the patches page shows the progress after a reload.
     */
    public static function dispatchManual(Server $server): void
    {
        Cache::put(self::runningCacheKey($server), true, now()->addHour());
        self::dispatch($server);
    }

    public function handle(): void
    {
        $server = $this->server->fresh(['settings']);
        if (! $server instanceof Server) {
            return;
        }

        if (! ScanHostPackages::run($server)) {
            if ($this->attempts() < $this->tries) {
                $this->release(self::RETRY_DELAY_SECONDS);

                return;
            }
            ScanHostPackages::recordFailure($server, ImageScanErrorCode::LockBusy);
        }

        Cache::forget(self::runningCacheKey($server));
        ServerPatchCheckFinished::dispatch($server->team_id, $server->uuid);
    }

    public function failed(): void
    {
        Cache::forget(self::runningCacheKey($this->server));
    }
}
