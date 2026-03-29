<?php

namespace App\Jobs;

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyOutdatedTraefikServersJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function __construct(public string $checkedAt)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $servers = Server::whereNotNull('proxy')
            ->whereProxyType(ProxyTypes::TRAEFIK->value)
            ->whereRelation('settings', 'is_reachable', true)
            ->whereRelation('settings', 'is_usable', true)
            ->get();

        $outdatedServers = $servers->filter(function (Server $server): bool {
            $outdatedInfo = $server->traefik_outdated_info;

            if (! $outdatedInfo || ($outdatedInfo['checked_at'] ?? null) !== $this->checkedAt) {
                return false;
            }

            $server->outdatedInfo = $outdatedInfo;

            return true;
        });

        if ($outdatedServers->isEmpty()) {
            return;
        }

        $outdatedServers
            ->groupBy('team_id')
            ->each(function ($teamServers, int|string $teamId): void {
                $team = Team::find($teamId);

                if (! $team) {
                    return;
                }

                $team->notify(new TraefikVersionOutdated($teamServers->values()));
            });
    }
}
