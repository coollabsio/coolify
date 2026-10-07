<?php

namespace App\Jobs;

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChangedUI;
use App\Models\Server;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckTraefikVersionForServerJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 60;

    private ?array $previousOutdatedInfo = null;

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
        $this->server->refresh();
        $this->previousOutdatedInfo = $this->server->traefik_outdated_info;
        $this->clearOutdatedInfo();

        if ($this->server->proxyType() !== ProxyTypes::TRAEFIK->value || $this->server->proxy->get('status') !== ProxyStatus::RUNNING->value) {
            return;
        }

        // Detect current version (makes SSH call)
        $currentVersion = getTraefikVersionFromDockerCompose($this->server);

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

        if ($newerBranchInfo) {
            $this->storeOutdatedInfo($current, $newerBranchInfo['latest'], 'minor_upgrade', $newerBranchInfo['target']);
        } elseif (version_compare($current, $latest, '<')) {
            $this->storeOutdatedInfo($current, $latest, 'patch_update');
        } else {
            // Fully up to date
            $this->server->update(['traefik_outdated_info' => null]);
        }

        // Dispatch UI update event so warning state refreshes in real-time
        ProxyStatusChangedUI::dispatch($this->server->team_id);
    }

    private function clearOutdatedInfo(): void
    {
        $this->server->update([
            'detected_traefik_version' => null,
            'traefik_outdated_info' => null,
        ]);
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
     * Store outdated information and notify for minor or major upgrades.
     */
    private function storeOutdatedInfo(string $current, string $latest, string $type, ?string $upgradeTarget = null): void
    {
        $previousOutdatedInfo = $this->previousOutdatedInfo ?? $this->server->traefik_outdated_info;
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

        $this->server->update(['traefik_outdated_info' => $outdatedInfo]);

        $isRepeatedUpgrade = ($previousOutdatedInfo['type'] ?? null) === $type
            && ($previousOutdatedInfo['upgrade_target'] ?? null) === $upgradeTarget;

        if ($type !== 'patch_update' && ! $isRepeatedUpgrade) {
            $this->sendNotification($outdatedInfo);
        }

        $this->previousOutdatedInfo = $outdatedInfo;
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
