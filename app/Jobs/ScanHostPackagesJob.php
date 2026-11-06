<?php

namespace App\Jobs;

use App\Actions\ImageScan\ScanHostPackages;
use App\Events\ServerPatchCheckFinished;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Scans the OS packages of a server. A busy image scan lock runs the job again later. When it ends, the patches page
 * reloads through `ServerPatchCheckFinished`.
 */
class ScanHostPackagesJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
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

        if (! ScanHostPackages::run($server) && $this->attempts() < $this->tries) {
            $this->release(self::RETRY_DELAY_SECONDS);

            return;
        }

        Cache::forget(self::runningCacheKey($server));
        ServerPatchCheckFinished::dispatch($server->team_id, $server->uuid);
    }

    public function failed(): void
    {
        Cache::forget(self::runningCacheKey($this->server));
    }
}
