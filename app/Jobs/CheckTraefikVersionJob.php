<?php

namespace App\Jobs;

use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class CheckTraefikVersionJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const SCAN_LOCK_KEY = 'traefik-version-scan';

    private const SCAN_LOCK_TTL_SECONDS = 21600;

    public $tries = 3;

    public function handle(): void
    {
        // Load versions from cached data
        $traefikVersions = get_traefik_versions();

        if (empty($traefikVersions)) {
            return;
        }

        // Query all servers with Traefik proxy that are reachable
        $servers = Server::whereNotNull('proxy')
            ->whereProxyType(ProxyTypes::TRAEFIK->value)
            ->whereRelation('settings', 'is_reachable', true)
            ->whereRelation('settings', 'is_usable', true)
            ->get();

        if ($servers->isEmpty()) {
            return;
        }

        $lock = Cache::lock(self::SCAN_LOCK_KEY, self::SCAN_LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            return;
        }

        $lockOwner = $lock->owner();
        $scanId = (string) Str::uuid();
        $jobs = $servers
            ->map(fn (Server $server) => new CheckTraefikVersionForServerJob($server, $traefikVersions, false, $scanId))
            ->all();

        try {
            Bus::batch($jobs)
                ->finally(function (Batch $batch) use ($scanId, $lockOwner): void {
                    if ($batch->cancelled()) {
                        self::releaseScanLock($lockOwner);

                        return;
                    }

                    try {
                        NotifyOutdatedTraefikServersJob::dispatch($scanId, self::SCAN_LOCK_KEY, $lockOwner);
                    } catch (Throwable $exception) {
                        self::releaseScanLock($lockOwner);

                        throw $exception;
                    }
                })
                ->dispatch();
        } catch (Throwable $exception) {
            self::releaseScanLock($lockOwner);

            throw $exception;
        }
    }

    private static function releaseScanLock(?string $lockOwner): void
    {
        if (! $lockOwner) {
            return;
        }

        rescue(
            fn () => Cache::restoreLock(self::SCAN_LOCK_KEY, $lockOwner)->release(),
            report: false
        );
    }
}
