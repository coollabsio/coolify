<?php

namespace App\Jobs;

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTraefikOutdatedNotificationJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $servers = Server::whereNotNull('traefik_outdated_info')
            ->whereProxyType(ProxyTypes::TRAEFIK->value)
            ->whereRelation('settings', 'is_reachable', true)
            ->with('team')
            ->get();

        if ($servers->isEmpty()) {
            return;
        }

        // Only include servers whose check is newer than the last notification
        $serversToNotify = $servers->filter(function ($server) {
            $info = $server->traefik_outdated_info;
            $checkedAt = $info['checked_at'] ?? null;
            $notifiedAt = $info['notified_at'] ?? null;

            return $checkedAt && (! $notifiedAt || $checkedAt > $notifiedAt);
        });

        if ($serversToNotify->isEmpty()) {
            return;
        }

        $serversToNotify->groupBy('team_id')->each(function ($teamServers) {
            $team = $teamServers->first()->team;
            if (! $team) {
                return;
            }

            $team->notify(new TraefikVersionOutdated($teamServers, bundledOnly: true));
        });

        // Mark servers as notified so they aren't re-notified next week
        $serversToNotify->each(function ($server) {
            $info = $server->traefik_outdated_info;
            $info['notified_at'] = now()->toIso8601String();
            $server->update(['traefik_outdated_info' => $info]);
        });
    }
}
