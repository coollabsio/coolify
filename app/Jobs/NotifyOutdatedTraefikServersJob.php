<?php

namespace App\Jobs;

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyOutdatedTraefikServersJob implements ShouldBeEncrypted, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function __construct(
        public int $teamId,
        public string $scanId
    )
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $teamServers = self::matchingServersForScan($this->scanId, $this->teamId);

        if ($teamServers->isEmpty()) {
            return;
        }

        $team = $teamServers->first()?->team;
        if (! $team) {
            return;
        }

        $team->notify(new TraefikVersionOutdated($teamServers));
    }

    public static function teamIdsForScan(string $scanId): Collection
    {
        return self::matchingServersForScan($scanId)
            ->pluck('team_id')
            ->filter(static fn (?int $teamId): bool => $teamId !== null)
            ->unique()
            ->values();
    }

    private static function matchingServersForScan(string $scanId, ?int $teamId = null): Collection
    {
        $query = Server::whereNotNull('proxy')
            ->with('team')
            ->whereProxyType(ProxyTypes::TRAEFIK->value)
            ->whereRelation('settings', 'is_reachable', true)
            ->whereRelation('settings', 'is_usable', true);

        if ($teamId !== null) {
            $query->where('team_id', $teamId);
        }

        return $query->get()
            ->filter(function (Server $server) use ($scanId): bool {
                $outdatedInfo = $server->traefik_outdated_info;

                if (! $outdatedInfo || ($outdatedInfo['scan_id'] ?? null) !== $scanId) {
                    return false;
                }

                $server->outdatedInfo = $outdatedInfo;

                return true;
            })
            ->values();
    }
}
