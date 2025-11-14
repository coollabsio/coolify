<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
    ) {
        $this->onQueue('high');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::debug("CheckTraefikVersionForServerJob: Processing server '{$this->server->name}' (ID: {$this->server->id})");

            // Detect current version (makes SSH call)
            $currentVersion = getTraefikVersionFromDockerCompose($this->server);

            Log::info("CheckTraefikVersionForServerJob: Server '{$this->server->name}' - Detected version: ".($currentVersion ?? 'unable to detect'));

            // Update detected version in database
            $this->server->update(['detected_traefik_version' => $currentVersion]);

            if (! $currentVersion) {
                Log::warning("CheckTraefikVersionForServerJob: Server '{$this->server->name}' - Unable to detect version, skipping");

                return;
            }

            // Check if image tag is 'latest' by inspecting the image (makes SSH call)
            $imageTag = instant_remote_process([
                "docker inspect coolify-proxy --format '{{.Config.Image}}' 2>/dev/null",
            ], $this->server, false);

            if (str_contains(strtolower(trim($imageTag)), ':latest')) {
                Log::info("CheckTraefikVersionForServerJob: Server '{$this->server->name}' uses 'latest' tag, skipping notification (UI warning only)");

                return;
            }

            // Parse current version to extract major.minor.patch
            $current = ltrim($currentVersion, 'v');
            if (! preg_match('/^(\d+\.\d+)\.(\d+)$/', $current, $matches)) {
                Log::warning("CheckTraefikVersionForServerJob: Server '{$this->server->name}' - Invalid version format '{$current}', skipping");

                return;
            }

            $currentBranch = $matches[1]; // e.g., "3.6"
            $currentPatch = $matches[2];  // e.g., "0"

            Log::debug("CheckTraefikVersionForServerJob: Server '{$this->server->name}' - Parsed branch: {$currentBranch}, patch: {$currentPatch}");

            // Find the latest version for this branch
            $latestForBranch = $this->traefikVersions["v{$currentBranch}"] ?? null;

            if (! $latestForBranch) {
                // User is on a branch we don't track - check if newer branches exist
                $this->checkForNewerBranch($current, $currentBranch);

                return;
            }

            // Compare patch version within the same branch
            $latest = ltrim($latestForBranch, 'v');

            if (version_compare($current, $latest, '<')) {
                Log::info("CheckTraefikVersionForServerJob: Server '{$this->server->name}' is outdated - current: {$current}, latest for branch: {$latest}");
                $this->storeOutdatedInfo($current, $latest, 'patch_update');
            } else {
                // Check if newer branches exist
                $this->checkForNewerBranch($current, $currentBranch);
            }
        } catch (\Throwable $e) {
            Log::error("CheckTraefikVersionForServerJob: Error checking server '{$this->server->name}': ".$e->getMessage(), [
                'server_id' => $this->server->id,
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Check if there are newer branches available.
     */
    private function checkForNewerBranch(string $current, string $currentBranch): void
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
            Log::info("CheckTraefikVersionForServerJob: Server '{$this->server->name}' - newer branch {$newestBranch} available ({$newestVersion})");
            $this->storeOutdatedInfo($current, $newestVersion, 'minor_upgrade');
        } else {
            Log::info("CheckTraefikVersionForServerJob: Server '{$this->server->name}' is fully up to date - version: {$current}");
            // Clear any outdated info using schemaless attributes
            $this->server->extra_attributes->forget('traefik_outdated_info');
            $this->server->save();
        }
    }

    /**
     * Store outdated information using schemaless attributes.
     */
    private function storeOutdatedInfo(string $current, string $latest, string $type): void
    {
        // Store in schemaless attributes for persistence
        $this->server->extra_attributes->set('traefik_outdated_info', [
            'current' => $current,
            'latest' => $latest,
            'type' => $type,
            'checked_at' => now()->toIso8601String(),
        ]);
        $this->server->save();
    }
}
