<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class CleanupDocker
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Server $server, bool $deleteUnusedVolumes = false, bool $deleteUnusedNetworks = false)
    {
        $realtimeImage = config('constants.coolify.realtime_image');
        $realtimeImageVersion = config('constants.coolify.realtime_version');
        $realtimeImageWithVersion = "$realtimeImage:$realtimeImageVersion";
        $realtimeImageWithoutPrefix = 'coollabsio/coolify-realtime';
        $realtimeImageWithoutPrefixVersion = "coollabsio/coolify-realtime:$realtimeImageVersion";

        $helperImageVersion = getHelperVersion();
        $helperImage = config('constants.coolify.helper_image');
        $helperImageWithVersion = "$helperImage:$helperImageVersion";
        $helperImageWithoutPrefix = 'coollabsio/coolify-helper';
        $helperImageWithoutPrefixVersion = "coollabsio/coolify-helper:$helperImageVersion";

        $cleanupLog = [];

        // Get all application image repositories to exclude from prune
        $applications = $server->applications();
        $applicationImageRepos = collect($applications)->map(function ($app) {
            return $app->docker_registry_image_name ?? $app->uuid;
        })->unique()->values();

        // Clean up old application images while preserving N most recent for rollback
        $applicationCleanupLog = $this->cleanupApplicationImages($server, $applications);
        $cleanupLog = array_merge($cleanupLog, $applicationCleanupLog);

        // Build image prune command that excludes application images and current Coolify infrastructure images
        // This ensures we clean up non-Coolify images while preserving rollback images and current helper/realtime images
        // Note: Only the current version is protected; old versions will be cleaned up by explicit commands below
        // We pass the version strings so all registry variants are protected (ghcr.io, docker.io, no prefix)
        $imagePruneCmd = $this->buildImagePruneCommand(
            $applicationImageRepos,
            $helperImageVersion,
            $realtimeImageVersion
        );

        $commands = [
            'docker container prune -f --filter "label=coolify.managed=true" --filter "label!=coolify.proxy=true"',
            $imagePruneCmd,
            'docker builder prune -af',
            "docker images --filter before=$helperImageWithVersion --filter reference=$helperImage | grep $helperImage | awk '{print $3}' | xargs -r docker rmi -f",
            "docker images --filter before=$realtimeImageWithVersion --filter reference=$realtimeImage | grep $realtimeImage | awk '{print $3}' | xargs -r docker rmi -f",
            "docker images --filter before=$helperImageWithoutPrefixVersion --filter reference=$helperImageWithoutPrefix | grep $helperImageWithoutPrefix | awk '{print $3}' | xargs -r docker rmi -f",
            "docker images --filter before=$realtimeImageWithoutPrefixVersion --filter reference=$realtimeImageWithoutPrefix | grep $realtimeImageWithoutPrefix | awk '{print $3}' | xargs -r docker rmi -f",
        ];

        if ($deleteUnusedVolumes) {
            $commands[] = 'docker volume prune -af';
        }

        if ($deleteUnusedNetworks) {
            $commands[] = 'docker network prune -f';
        }

        foreach ($commands as $command) {
            $commandOutput = instant_remote_process([$command], $server, false);
            if ($commandOutput !== null) {
                $cleanupLog[] = [
                    'command' => $command,
                    'output' => $commandOutput,
                ];
            }
        }

        return $cleanupLog;
    }

    /**
     * Build a docker image prune command that excludes application image repositories.
     *
     * Since docker image prune doesn't support excluding by repository name directly,
     * we use a shell script approach to delete unused images while preserving application images.
     */
    private function buildImagePruneCommand(
        $applicationImageRepos,
        string $helperImageVersion,
        string $realtimeImageVersion
    ): string {
        // Step 1: Always prune dangling images (untagged)
        $commands = ['docker image prune -f'];

        // Build grep pattern to exclude application image repositories (matches repo:tag and repo_service:tag)
        $appExcludePatterns = $applicationImageRepos->map(function ($repo) {
            // Escape special characters for grep extended regex (ERE)
            // ERE special chars: . \ + * ? [ ^ ] $ ( ) { } |
            return preg_replace('/([.\\\\+*?\[\]^$(){}|])/', '\\\\$1', $repo);
        })->implode('|');

        // Build grep pattern to exclude Coolify infrastructure images (current version only)
        // This pattern matches the image name regardless of registry prefix:
        // - ghcr.io/coollabsio/coolify-helper:1.0.12
        // - docker.io/coollabsio/coolify-helper:1.0.12
        // - coollabsio/coolify-helper:1.0.12
        // Pattern: (^|/)coollabsio/coolify-(helper|realtime):VERSION$
        $escapedHelperVersion = preg_replace('/([.\\\\+*?\[\]^$(){}|])/', '\\\\$1', $helperImageVersion);
        $escapedRealtimeVersion = preg_replace('/([.\\\\+*?\[\]^$(){}|])/', '\\\\$1', $realtimeImageVersion);
        $infraExcludePattern = "(^|/)coollabsio/coolify-helper:{$escapedHelperVersion}$|(^|/)coollabsio/coolify-realtime:{$escapedRealtimeVersion}$";

        // Delete unused images that:
        // - Are not application images (don't match app repos)
        // - Are not current Coolify infrastructure images (any registry)
        // - Don't have coolify.managed=true label
        // Images in use by containers will fail silently with docker rmi
        // Pattern matches both uuid:tag and uuid_servicename:tag (Docker Compose with build)
        $grepCommands = "grep -v '<none>'";

        // Add application repo exclusion if there are applications
        if ($applicationImageRepos->isNotEmpty()) {
            $grepCommands .= " | grep -v -E '^({$appExcludePatterns})[_:].+'";
        }

        // Add infrastructure image exclusion (matches any registry prefix)
        $grepCommands .= " | grep -v -E '{$infraExcludePattern}'";

        $commands[] = "docker images --format '{{.Repository}}:{{.Tag}}' | ".
            $grepCommands.' | '.
            "xargs -r -I {} sh -c 'docker inspect --format \"{{{{index .Config.Labels \\\"coolify.managed\\\"}}}}\" \"{}\" 2>/dev/null | grep -q true || docker rmi \"{}\" 2>/dev/null' || true";

        return implode(' && ', $commands);
    }

    private function cleanupApplicationImages(Server $server, $applications = null): array
    {
        $cleanupLog = [];

        if ($applications === null) {
            $applications = $server->applications();
        }

        $disableRetention = $server->settings->disable_application_image_retention ?? false;

        foreach ($applications as $application) {
            $imageRepository = $application->docker_registry_image_name ?? $application->uuid;

            // Get image names to keep from deployment snapshots
            // This ensures we keep images that have corresponding deployment configurations
            $protectedImages = $this->getProtectedImagesFromSnapshots($server, $application, $disableRetention);

            // Get the currently running image (always protected)
            $currentImageCommand = "docker inspect --format='{{.Config.Image}}' {$application->uuid} 2>/dev/null || true";
            $currentImage = trim(instant_remote_process([$currentImageCommand], $server, false) ?? '');
            if (! empty($currentImage)) {
                $protectedImages[] = $currentImage;
            }

            // List all images for this application
            // Use wildcard to match both uuid:tag and uuid_servicename:tag (Docker Compose with build)
            $listCommand = "docker images --format '{{.Repository}}:{{.Tag}}' --filter reference='{$imageRepository}*' 2>/dev/null || true";
            $output = instant_remote_process([$listCommand], $server, false);

            if (empty($output)) {
                continue;
            }

            $images = collect(explode("\n", trim($output)))
                ->filter()
                ->filter(fn ($imageRef) => ! empty($imageRef) && $imageRef !== '<none>:<none>');

            foreach ($images as $imageRef) {
                // Skip protected images (those with deployment snapshots or currently running)
                if (in_array($imageRef, $protectedImages)) {
                    continue;
                }

                // Always delete PR images (pr-*)
                // Delete any image not in the protected list
                $deleteCommand = "docker rmi {$imageRef} 2>/dev/null || true";
                $deleteOutput = instant_remote_process([$deleteCommand], $server, false);
                $cleanupLog[] = [
                    'command' => $deleteCommand,
                    'output' => $deleteOutput ?? 'Image removed or was in use',
                ];
            }
        }

        return $cleanupLog;
    }

    /**
     * Get list of image names to protect from cleanup based on deployment snapshots.
     * Reads metadata.json from each deployment snapshot directory to get the image names.
     */
    private function getProtectedImagesFromSnapshots(Server $server, $application, bool $disableRetention): array
    {
        if ($disableRetention) {
            return [];
        }

        $deploymentsBaseDir = deployments_base_dir($application->uuid);
        $deploymentsToKeep = $application->settings->docker_images_to_keep ?? 2;

        // List deployment directories sorted by modification time (newest first)
        $listCommand = "ls -1t {$deploymentsBaseDir} 2>/dev/null | head -n {$deploymentsToKeep}";
        $output = instant_remote_process([$listCommand], $server, false);

        if (empty(trim($output ?? ''))) {
            return [];
        }

        $deploymentDirs = collect(explode("\n", trim($output)))->filter();
        $protectedImages = [];

        foreach ($deploymentDirs as $deploymentUuid) {
            $metadataPath = "{$deploymentsBaseDir}/{$deploymentUuid}/metadata.json";
            $metadataJson = instant_remote_process(["cat {$metadataPath} 2>/dev/null || echo '{}'"], $server, false);
            $metadata = json_decode($metadataJson ?? '{}', true) ?? [];

            if (! empty($metadata['image_name'])) {
                $protectedImages[] = $metadata['image_name'];
            }
        }

        return $protectedImages;
    }
}
