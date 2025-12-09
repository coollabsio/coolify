<?php

namespace App\Jobs;

use App\Events\ProxyStatusChangedUI;
use App\Models\Server;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckTraefikVersionForServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Server $server,
        public array $traefikVersions
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Detect current version (makes SSH call)
        $currentVersion = getTraefikVersionFromDockerCompose($this->server);

        // Update detected version in database
        $this->server->update(['detected_traefik_version' => $currentVersion]);

        if (! $currentVersion) {
            ProxyStatusChangedUI::dispatch($this->server->team_id);

            return;
        }

        // Check if image tag is 'latest' by inspecting the image (makes SSH call)
        $imageTag = instant_remote_process([
            "docker inspect coolify-proxy --format '{{.Config.Image}}' 2>/dev/null",
        ], $this->server, false);

        // Handle empty/null response from SSH command
        if (empty(trim($imageTag))) {
            ProxyStatusChangedUI::dispatch($this->server->team_id);

            return;
        }

        if (str_contains(strtolower(trim($imageTag)), ':latest')) {
            ProxyStatusChangedUI::dispatch($this->server->team_id);

            return;
        }

        // Parse current version to extract major.minor.patch
        $current = ltrim($currentVersion, 'v');
        if (! preg_match('/^(\d+\.\d+)\.(\d+)$/', $current, $matches)) {
            ProxyStatusChangedUI::dispatch($this->server->team_id);

            return;
        }

        $currentBranch = $matches[1]; // e.g., "3.6"

        // Find the latest version for this branch
        $latestForBranch = $this->traefikVersions["v{$currentBranch}"] ?? null;

        if (! $latestForBranch) {
            // User is on a branch we don't track - check if newer branches exist
            $newerBranchInfo = $this->getNewerBranchInfo($currentBranch);

            if ($newerBranchInfo) {
                $this->storeOutdatedInfo($current, $newerBranchInfo['latest'], 'minor_upgrade', $newerBranchInfo['target']);
            } else {
                // No newer branch found, clear outdated info
                $this->server->update(['traefik_outdated_info' => null]);
            }

            ProxyStatusChangedUI::dispatch($this->server->team_id);

            return;
        }

        // Compare patch version within the same branch
        $latest = ltrim($latestForBranch, 'v');

        // Always check for newer branches first
        $newerBranchInfo = $this->getNewerBranchInfo($currentBranch);

        if (version_compare($current, $latest, '<')) {
            // Patch update available
            $this->storeOutdatedInfo($current, $latest, 'patch_update', null, $newerBranchInfo);
        } elseif ($newerBranchInfo) {
            // Only newer branch available (no patch update)
            $this->storeOutdatedInfo($current, $newerBranchInfo['latest'], 'minor_upgrade', $newerBranchInfo['target']);
        } else {
            // Fully up to date
            $this->server->update(['traefik_outdated_info' => null]);
        }

        // Dispatch UI update event so warning state refreshes in real-time
        ProxyStatusChangedUI::dispatch($this->server->team_id);
    }

    /**
     * Get information about newer branches if available.
     */
    private function getNewerBranchInfo(string $currentBranch): ?array
    {
        $newestBranch = null;
        $newestVersion = null;

        foreach ($this->traefikVersions as $branch => $version) {
            $branchNum = ltrim($branch, 'v');
            if (version_compare($branchNum, $currentBranch, '>')) {
                if (! $newestVersion || version_compare($version, $newestVersion, '>')) {
                    $newestBranch = $branchNum;
                    $newestVersion = $version;
                }
            }
        }

        if ($newestVersion) {
            return [
                'target' => "v{$newestBranch}",
                'latest' => ltrim($newestVersion, 'v'),
            ];
        }

        return null;
    }

    /**
     * Store outdated information in database and send immediate notification.
     */
    private function storeOutdatedInfo(string $current, string $latest, string $type, ?string $upgradeTarget = null, ?array $newerBranchInfo = null): void
    {
        $outdatedInfo = [
            'current' => $current,
            'latest' => $latest,
            'type' => $type,
            'checked_at' => now()->toIso8601String(),
        ];

        // For minor upgrades, add the upgrade_target field (e.g., "v3.6")
        if ($type === 'minor_upgrade' && $upgradeTarget) {
            $outdatedInfo['upgrade_target'] = $upgradeTarget;
        }

        // If there's a newer branch available (even for patch updates), include that info
        if ($newerBranchInfo) {
            $outdatedInfo['newer_branch_target'] = $newerBranchInfo['target'];
            $outdatedInfo['newer_branch_latest'] = $newerBranchInfo['latest'];
        }

        $this->server->update(['traefik_outdated_info' => $outdatedInfo]);

        // Send immediate notification to the team
        $this->sendNotification($outdatedInfo);
    }

    /**
     * Send notification to team about outdated Traefik.
     */
    private function sendNotification(array $outdatedInfo): void
    {
        // Attach the outdated info as a dynamic property for the notification
        $this->server->outdatedInfo = $outdatedInfo;

        // Get the team and send notification
        $team = $this->server->team()->first();

        if ($team) {
            $team->notify(new TraefikVersionOutdated(collect([$this->server])));
        }
    }
}
