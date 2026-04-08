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
    private const SCAN_SNAPSHOT_CACHE_KEY_PREFIX = 'traefik-version-scan:snapshot:';
    private const SCAN_SNAPSHOT_LOCK_KEY_PREFIX = 'traefik-version-scan:snapshot-lock:';

    private const SCAN_LOCK_TTL_SECONDS = 21600;
    private const SCAN_SNAPSHOT_LOCK_TTL_SECONDS = 10;

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
                        self::forgetOutdatedServerSnapshots($scanId);
                        self::releaseScanLock($lockOwner);

                        return;
                    }

                    self::dispatchNotificationJobs($scanId, $lockOwner);
                })
                ->dispatch();
        } catch (Throwable $exception) {
            self::forgetOutdatedServerSnapshots($scanId);
            self::releaseScanLock($lockOwner);

            throw $exception;
        }
    }

    public static function recordOutdatedServerSnapshot(string $scanId, Server $server, array $outdatedInfo): void
    {
        Cache::lock(self::snapshotLockKey($scanId), self::SCAN_SNAPSHOT_LOCK_TTL_SECONDS)
            ->block(self::SCAN_SNAPSHOT_LOCK_TTL_SECONDS, function () use ($scanId, $server, $outdatedInfo): void {
                $snapshots = Cache::get(self::snapshotCacheKey($scanId), []);

                $teamSnapshots = $snapshots[$server->team_id] ?? [];
                $teamSnapshots[$server->id] = [
                    'id' => $server->id,
                    'name' => $server->name,
                    'uuid' => $server->uuid,
                    'outdatedInfo' => $outdatedInfo,
                ];

                $snapshots[$server->team_id] = $teamSnapshots;

                Cache::put(self::snapshotCacheKey($scanId), $snapshots, self::SCAN_LOCK_TTL_SECONDS);
            });
    }

    private static function dispatchNotificationJobs(string $scanId, ?string $lockOwner): void
    {
        $serverSnapshotsByTeam = collect(Cache::get(self::snapshotCacheKey($scanId), []));

        if ($serverSnapshotsByTeam->isEmpty()) {
            self::forgetOutdatedServerSnapshots($scanId);
            self::releaseScanLock($lockOwner);

            return;
        }

        $jobs = $serverSnapshotsByTeam
            ->map(fn (array $teamServers, int|string $teamId) => new NotifyOutdatedTraefikServersJob($teamId, $scanId, array_values($teamServers)))
            ->all();

        try {
            Bus::batch($jobs)
                ->allowFailures()
                ->finally(function () use ($scanId, $lockOwner): void {
                    self::forgetOutdatedServerSnapshots($scanId);
                    self::releaseScanLock($lockOwner);
                })
                ->dispatch();
        } catch (Throwable $exception) {
            self::forgetOutdatedServerSnapshots($scanId);
            self::releaseScanLock($lockOwner);

            throw $exception;
        }
    }

    private static function forgetOutdatedServerSnapshots(string $scanId): void
    {
        Cache::forget(self::snapshotCacheKey($scanId));
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

    private static function snapshotCacheKey(string $scanId): string
    {
        return self::SCAN_SNAPSHOT_CACHE_KEY_PREFIX.$scanId;
    }

    private static function snapshotLockKey(string $scanId): string
    {
        return self::SCAN_SNAPSHOT_LOCK_KEY_PREFIX.$scanId;
    }
}
