<?php

namespace App\Jobs;

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class NotifyOutdatedTraefikServersJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function __construct(
        public string $scanId,
        public ?string $lockKey = null,
        public ?string $lockOwner = null
    )
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $servers = Server::whereNotNull('proxy')
            ->with('team')
            ->whereProxyType(ProxyTypes::TRAEFIK->value)
            ->whereRelation('settings', 'is_reachable', true)
            ->whereRelation('settings', 'is_usable', true)
            ->get();

        $outdatedServers = $servers->filter(function (Server $server): bool {
            $outdatedInfo = $server->traefik_outdated_info;

            if (! $outdatedInfo || ($outdatedInfo['scan_id'] ?? null) !== $this->scanId) {
                return false;
            }

            $server->outdatedInfo = $outdatedInfo;

            return true;
        });

        if ($outdatedServers->isEmpty()) {
            $this->releaseScanLock();

            return;
        }

        $outdatedServers
            ->groupBy('team_id')
            ->each(function ($teamServers): void {
                $team = $teamServers->first()?->team;

                if (! $team) {
                    return;
                }

                $team->notify(new TraefikVersionOutdated($teamServers->values()));
            });

        $this->releaseScanLock();
    }

    public function failed(?Throwable $exception = null): void
    {
        $this->releaseScanLock();
    }

    private function releaseScanLock(): void
    {
        if (! $this->lockKey || ! $this->lockOwner) {
            return;
        }

        rescue(
            fn () => Cache::restoreLock($this->lockKey, $this->lockOwner)->release(),
            report: false
        );

        $this->lockKey = null;
        $this->lockOwner = null;
    }
}
