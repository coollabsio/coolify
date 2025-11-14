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
use Illuminate\Support\Facades\Log;

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
        try {
            Log::info('NotifyOutdatedTraefikServersJob: Starting notification aggregation');

            // Query servers that have outdated info stored
            $servers = Server::whereNotNull('proxy')
                ->whereProxyType(ProxyTypes::TRAEFIK->value)
                ->whereRelation('settings', 'is_reachable', true)
                ->whereRelation('settings', 'is_usable', true)
                ->get();

            $outdatedServers = collect();

            foreach ($servers as $server) {
                $outdatedInfo = $server->extra_attributes->get('traefik_outdated_info');

                if ($outdatedInfo) {
                    // Attach the outdated info as a dynamic property for the notification
                    $server->outdatedInfo = $outdatedInfo;
                    $outdatedServers->push($server);
                }
            }

            $outdatedCount = $outdatedServers->count();
            Log::info("NotifyOutdatedTraefikServersJob: Found {$outdatedCount} outdated server(s)");

            if ($outdatedCount === 0) {
                Log::info('NotifyOutdatedTraefikServersJob: No outdated servers found, no notifications to send');

                return;
            }

            // Group by team and send notifications
            $serversByTeam = $outdatedServers->groupBy('team_id');
            $teamCount = $serversByTeam->count();

            Log::info("NotifyOutdatedTraefikServersJob: Grouped outdated servers into {$teamCount} team(s)");

            foreach ($serversByTeam as $teamId => $teamServers) {
                $team = Team::find($teamId);
                if (! $team) {
                    Log::warning("NotifyOutdatedTraefikServersJob: Team ID {$teamId} not found, skipping");

                    continue;
                }

                $serverNames = $teamServers->pluck('name')->join(', ');
                Log::info("NotifyOutdatedTraefikServersJob: Sending notification to team '{$team->name}' for {$teamServers->count()} server(s): {$serverNames}");

                // Send one notification per team with all outdated servers
                $team->notify(new TraefikVersionOutdated($teamServers));

                Log::info("NotifyOutdatedTraefikServersJob: Notification sent to team '{$team->name}'");
            }

            Log::info('NotifyOutdatedTraefikServersJob: Job completed successfully');
        } catch (\Throwable $e) {
            Log::error('NotifyOutdatedTraefikServersJob: Error sending notifications: '.$e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
