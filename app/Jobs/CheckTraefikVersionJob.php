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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CheckTraefikVersionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function handle(): void
    {
        try {
            Log::info('CheckTraefikVersionJob: Starting Traefik version check');

            // Load versions from versions.json
            $versionsPath = base_path('versions.json');
            if (! File::exists($versionsPath)) {
                Log::warning('CheckTraefikVersionJob: versions.json not found, skipping check');

                return;
            }

            $allVersions = json_decode(File::get($versionsPath), true);
            $traefikVersions = data_get($allVersions, 'traefik');

            if (empty($traefikVersions) || ! is_array($traefikVersions)) {
                Log::warning('CheckTraefikVersionJob: Traefik versions not found or invalid in versions.json');

                return;
            }

            $branches = array_keys($traefikVersions);
            Log::info('CheckTraefikVersionJob: Loaded Traefik version branches', ['branches' => $branches]);

            // Query all servers with Traefik proxy that are reachable
            $servers = Server::whereNotNull('proxy')
                ->whereProxyType(ProxyTypes::TRAEFIK->value)
                ->whereRelation('settings', 'is_reachable', true)
                ->whereRelation('settings', 'is_usable', true)
                ->get();

            $serverCount = $servers->count();
            Log::info("CheckTraefikVersionJob: Found {$serverCount} server(s) with Traefik proxy");

            if ($serverCount === 0) {
                Log::info('CheckTraefikVersionJob: No Traefik servers found, job completed');

                return;
            }

            $outdatedServers = collect();

            // Phase 1: Scan servers and detect versions
            Log::info('CheckTraefikVersionJob: Phase 1 - Scanning servers and detecting versions');

            foreach ($servers as $server) {
                $currentVersion = getTraefikVersionFromDockerCompose($server);

                Log::info("CheckTraefikVersionJob: Server '{$server->name}' - Detected version: ".($currentVersion ?? 'unable to detect'));

                // Update detected version in database
                $server->update(['detected_traefik_version' => $currentVersion]);

                if (! $currentVersion) {
                    Log::warning("CheckTraefikVersionJob: Server '{$server->name}' - Unable to detect version, skipping");

                    continue;
                }

                // Check if image tag is 'latest' by inspecting the image
                $imageTag = instant_remote_process([
                    "docker inspect coolify-proxy --format '{{.Config.Image}}' 2>/dev/null",
                ], $server, false);

                if (str_contains(strtolower(trim($imageTag)), ':latest')) {
                    Log::info("CheckTraefikVersionJob: Server '{$server->name}' uses 'latest' tag, skipping notification (UI warning only)");

                    continue;
                }

                // Parse current version to extract major.minor.patch
                $current = ltrim($currentVersion, 'v');
                if (! preg_match('/^(\d+\.\d+)\.(\d+)$/', $current, $matches)) {
                    Log::warning("CheckTraefikVersionJob: Server '{$server->name}' - Invalid version format '{$current}', skipping");

                    continue;
                }

                $currentBranch = $matches[1]; // e.g., "3.6"
                $currentPatch = $matches[2];  // e.g., "0"

                Log::debug("CheckTraefikVersionJob: Server '{$server->name}' - Parsed branch: {$currentBranch}, patch: {$currentPatch}");

                // Find the latest version for this branch
                $latestForBranch = $traefikVersions["v{$currentBranch}"] ?? null;

                if (! $latestForBranch) {
                    // User is on a branch we don't track - check if newer branches exist
                    Log::debug("CheckTraefikVersionJob: Server '{$server->name}' - Branch v{$currentBranch} not tracked, checking for newer branches");

                    $newestBranch = null;
                    $newestVersion = null;

                    foreach ($traefikVersions as $branch => $version) {
                        $branchNum = ltrim($branch, 'v');
                        if (version_compare($branchNum, $currentBranch, '>')) {
                            if (! $newestVersion || version_compare($version, $newestVersion, '>')) {
                                $newestBranch = $branchNum;
                                $newestVersion = $version;
                            }
                        }
                    }

                    if ($newestVersion) {
                        Log::info("CheckTraefikVersionJob: Server '{$server->name}' is outdated - on {$current}, newer branch {$newestBranch} with version {$newestVersion} available");
                        $server->outdatedInfo = [
                            'current' => $current,
                            'latest' => $newestVersion,
                            'type' => 'minor_upgrade',
                        ];
                        $outdatedServers->push($server);
                    } else {
                        Log::info("CheckTraefikVersionJob: Server '{$server->name}' on {$current} - no newer branches available");
                    }

                    continue;
                }

                // Compare patch version within the same branch
                $latest = ltrim($latestForBranch, 'v');

                if (version_compare($current, $latest, '<')) {
                    Log::info("CheckTraefikVersionJob: Server '{$server->name}' is outdated - current: {$current}, latest for branch: {$latest}");
                    $server->outdatedInfo = [
                        'current' => $current,
                        'latest' => $latest,
                        'type' => 'patch_update',
                    ];
                    $outdatedServers->push($server);
                } else {
                    // Check if newer branches exist (user is up to date on their branch, but branch might be old)
                    $newestBranch = null;
                    $newestVersion = null;

                    foreach ($traefikVersions as $branch => $version) {
                        $branchNum = ltrim($branch, 'v');
                        if (version_compare($branchNum, $currentBranch, '>')) {
                            if (! $newestVersion || version_compare($version, $newestVersion, '>')) {
                                $newestBranch = $branchNum;
                                $newestVersion = $version;
                            }
                        }
                    }

                    if ($newestVersion) {
                        Log::info("CheckTraefikVersionJob: Server '{$server->name}' up to date on branch {$currentBranch} ({$current}), but newer branch {$newestBranch} available ({$newestVersion})");
                        $server->outdatedInfo = [
                            'current' => $current,
                            'latest' => $newestVersion,
                            'type' => 'minor_upgrade',
                        ];
                        $outdatedServers->push($server);
                    } else {
                        Log::info("CheckTraefikVersionJob: Server '{$server->name}' is fully up to date - version: {$current}");
                    }
                }
            }

            $outdatedCount = $outdatedServers->count();
            Log::info("CheckTraefikVersionJob: Phase 1 complete - Found {$outdatedCount} outdated server(s)");

            if ($outdatedCount === 0) {
                Log::info('CheckTraefikVersionJob: All servers are up to date, no notifications to send');

                return;
            }

            // Phase 2: Group by team and send notifications
            Log::info('CheckTraefikVersionJob: Phase 2 - Grouping by team and sending notifications');

            $serversByTeam = $outdatedServers->groupBy('team_id');
            $teamCount = $serversByTeam->count();

            Log::info("CheckTraefikVersionJob: Grouped outdated servers into {$teamCount} team(s)");

            foreach ($serversByTeam as $teamId => $teamServers) {
                $team = Team::find($teamId);
                if (! $team) {
                    Log::warning("CheckTraefikVersionJob: Team ID {$teamId} not found, skipping");

                    continue;
                }

                $serverNames = $teamServers->pluck('name')->join(', ');
                Log::info("CheckTraefikVersionJob: Sending notification to team '{$team->name}' for {$teamServers->count()} server(s): {$serverNames}");

                // Send one notification per team with all outdated servers (with per-server info)
                $team->notify(new TraefikVersionOutdated($teamServers));

                Log::info("CheckTraefikVersionJob: Notification sent to team '{$team->name}'");
            }

            Log::info('CheckTraefikVersionJob: Job completed successfully');
        } catch (\Throwable $e) {
            Log::error('CheckTraefikVersionJob: Error checking Traefik versions: '.$e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
