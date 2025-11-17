<?php

namespace App\Jobs;

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyOutdatedTraefikServersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('high');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Query servers that have outdated info stored
        $servers = Server::whereNotNull('proxy')
            ->whereProxyType(ProxyTypes::TRAEFIK->value)
            ->whereRelation('settings', 'is_reachable', true)
            ->whereRelation('settings', 'is_usable', true)
            ->get();

        $outdatedServers = collect();

        foreach ($servers as $server) {
            if ($server->traefik_outdated_info) {
                // Attach the outdated info as a dynamic property for the notification
                $server->outdatedInfo = $server->traefik_outdated_info;
                $outdatedServers->push($server);
            }
        }

        if ($outdatedServers->isEmpty()) {
            return;
        }

        // Group by team and send notifications
        $serversByTeam = $outdatedServers->groupBy('team_id');

        foreach ($serversByTeam as $teamId => $teamServers) {
            $team = Team::find($teamId);
            if (! $team) {
                continue;
            }

            // Send one notification per team with all outdated servers
            $team->notify(new TraefikVersionOutdated($teamServers));
        }
    }
}
