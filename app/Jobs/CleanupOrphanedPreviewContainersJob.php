<?php

namespace App\Jobs;

use App\Models\ApplicationPreview;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled job to clean up orphaned PR preview containers.
 *
 * This job acts as a safety net for containers that weren't properly cleaned up
 * when a PR was closed (e.g., due to webhook failures, race conditions, etc.).
 *
 * It scans all functional servers for containers with the `coolify.pullRequestId` label
 * and removes any where the corresponding ApplicationPreview record no longer exists.
 */
class CleanupOrphanedPreviewContainersJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes max

    public function __construct() {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('cleanup-orphaned-preview-containers'))->expireAfter(600)->dontRelease()];
    }

    public function handle(): void
    {
        try {
            $servers = $this->getServersToCheck();

            foreach ($servers as $server) {
                $this->cleanupOrphanedContainersOnServer($server);
            }
        } catch (\Throwable $e) {
            Log::error('CleanupOrphanedPreviewContainersJob failed: '.$e->getMessage());
            send_internal_notification('CleanupOrphanedPreviewContainersJob failed with error: '.$e->getMessage());
        }
    }

    /**
     * Get all functional servers to check for orphaned containers.
     */
    private function getServersToCheck(): \Illuminate\Support\Collection
    {
        $query = Server::whereRelation('settings', 'is_usable', true)
            ->whereRelation('settings', 'is_reachable', true)
            ->where('ip', '!=', '1.2.3.4');

        if (isCloud()) {
            $query = $query->whereRelation('team.subscription', 'stripe_invoice_paid', true);
        }

        return $query->get()->filter(fn ($server) => $server->isFunctional());
    }

    /**
     * Find and clean up orphaned PR containers on a specific server.
     */
    private function cleanupOrphanedContainersOnServer(Server $server): void
    {
        try {
            $prContainers = $this->getPRContainersOnServer($server);

            if ($prContainers->isEmpty()) {
                return;
            }

            $orphanedCount = 0;
            foreach ($prContainers as $container) {
                if ($this->isOrphanedContainer($container)) {
                    $this->removeContainer($container, $server);
                    $orphanedCount++;
                }
            }

            if ($orphanedCount > 0) {
                Log::info("CleanupOrphanedPreviewContainersJob - Removed {$orphanedCount} orphaned PR containers", [
                    'server' => $server->name,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("CleanupOrphanedPreviewContainersJob - Error on server {$server->name}: {$e->getMessage()}");
        }
    }

    /**
     * Get all PR containers on a server (containers with pullRequestId > 0).
     */
    private function getPRContainersOnServer(Server $server): \Illuminate\Support\Collection
    {
        try {
            $output = instant_remote_process([
                "docker ps -a --filter 'label=coolify.pullRequestId' --format '{{json .}}'",
            ], $server, false);

            if (empty($output)) {
                return collect();
            }

            return format_docker_command_output_to_json($output)
                ->filter(function ($container) {
                    // Only include PR containers (pullRequestId > 0)
                    $prId = $this->extractPullRequestId($container);

                    return $prId !== null && $prId > 0;
                });
        } catch (\Throwable $e) {
            Log::debug("Failed to get PR containers on server {$server->name}: {$e->getMessage()}");

            return collect();
        }
    }

    /**
     * Extract pull request ID from container labels.
     */
    private function extractPullRequestId($container): ?int
    {
        $labels = data_get($container, 'Labels', '');
        if (preg_match('/coolify\.pullRequestId=(\d+)/', $labels, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Extract application ID from container labels.
     */
    private function extractApplicationId($container): ?int
    {
        $labels = data_get($container, 'Labels', '');
        if (preg_match('/coolify\.applicationId=(\d+)/', $labels, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Check if a container is orphaned (no corresponding ApplicationPreview record).
     */
    private function isOrphanedContainer($container): bool
    {
        $applicationId = $this->extractApplicationId($container);
        $pullRequestId = $this->extractPullRequestId($container);

        if ($applicationId === null || $pullRequestId === null) {
            return false;
        }

        // Check if ApplicationPreview record exists (including soft-deleted)
        $previewExists = ApplicationPreview::withTrashed()
            ->where('application_id', $applicationId)
            ->where('pull_request_id', $pullRequestId)
            ->exists();

        // If preview exists (even soft-deleted), container should be handled by DeleteResourceJob
        // If preview doesn't exist at all, it's truly orphaned
        return ! $previewExists;
    }

    /**
     * Remove an orphaned container from the server.
     */
    private function removeContainer($container, Server $server): void
    {
        $containerName = data_get($container, 'Names');

        if (empty($containerName)) {
            Log::warning('CleanupOrphanedPreviewContainersJob - Cannot remove container: missing container name', [
                'container_data' => $container,
                'server' => $server->name,
            ]);

            return;
        }

        $applicationId = $this->extractApplicationId($container);
        $pullRequestId = $this->extractPullRequestId($container);

        Log::info('CleanupOrphanedPreviewContainersJob - Removing orphaned container', [
            'container' => $containerName,
            'application_id' => $applicationId,
            'pull_request_id' => $pullRequestId,
            'server' => $server->name,
        ]);

        $escapedContainerName = escapeshellarg($containerName);

        try {
            instant_remote_process(
                ["docker rm -f {$escapedContainerName}"],
                $server,
                false
            );
        } catch (\Throwable $e) {
            Log::warning("Failed to remove orphaned container {$containerName}: {$e->getMessage()}");
        }
    }
}
