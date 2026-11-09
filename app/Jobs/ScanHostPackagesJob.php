<?php

namespace App\Jobs;

use App\Actions\ImageScan\ScanHostPackages;
use App\Actions\ImageScan\ScanImage;
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
use Illuminate\Support\Str;

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

    /** A run never takes longer than the server scan lock lives, so the worker does not stop it before the cleanup. */
    public $timeout = ScanImage::LOCK_TTL;

    public $uniqueFor = ScanImage::LOCK_TTL;

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
     * Queues a scan and marks it as running, so the patches page shows the progress after a reload. The mark lasts
     * for the longest wait in the queue and the longest run. Each request writes a new token, so a scan that started
     * before this request cannot clear the marker of this request.
     */
    public static function dispatchManual(Server $server): void
    {
        Cache::put(self::runningCacheKey($server), (string) Str::uuid(), now()->addSeconds(2 * ScanImage::LOCK_TTL));
        self::dispatch($server);
    }

    public function handle(): void
    {
        $server = $this->server->fresh(['settings']);
        if (! $server instanceof Server) {
            return;
        }

        $request = Cache::get(self::runningCacheKey($server));

        if (! ScanHostPackages::run($server)) {
            if ($this->attempts() < $this->tries) {
                $this->release(self::RETRY_DELAY_SECONDS);

                return;
            }
            ScanHostPackages::recordFailure($server, ImageScanErrorCode::LockBusy);
        }

        // A request made during this scan queued a new scan, which clears the marker when it ends.
        if (Cache::get(self::runningCacheKey($server)) === $request) {
            Cache::forget(self::runningCacheKey($server));
        }
        ServerPatchCheckFinished::dispatch($server->team_id, $server->uuid);
    }

    public function failed(): void
    {
        Cache::forget(self::runningCacheKey($this->server));
    }
}
