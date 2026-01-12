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
            $imagesToKeep = $disableRetention ? 0 : ($application->settings->docker_images_to_keep ?? 2);
            $imageRepository = $application->docker_registry_image_name ?? $application->uuid;

            // Get the currently running image tag
            $currentTagCommand = "docker inspect --format='{{.Config.Image}}' {$application->uuid} 2>/dev/null | grep -oP '(?<=:)[^:]+$' || true";
            $currentTag = instant_remote_process([$currentTagCommand], $server, false);
            $currentTag = trim($currentTag ?? '');

            // List all images for this application with their creation timestamps
            // Use wildcard to match both uuid:tag and uuid_servicename:tag (Docker Compose with build)
            $listCommand = "docker images --format '{{.Repository}}:{{.Tag}}#{{.CreatedAt}}' --filter reference='{$imageRepository}*' 2>/dev/null || true";
            $output = instant_remote_process([$listCommand], $server, false);

            if (empty($output)) {
                continue;
            }

            $images = collect(explode("\n", trim($output)))
                ->filter()
                ->map(function ($line) {
                    $parts = explode('#', $line);
                    $imageRef = $parts[0] ?? '';
                    $tagParts = explode(':', $imageRef);

                    return [
                        'repository' => $tagParts[0] ?? '',
                        'tag' => $tagParts[1] ?? '',
                        'created_at' => $parts[1] ?? '',
                        'image_ref' => $imageRef,
                    ];
                })
                ->filter(fn ($image) => ! empty($image['tag']));

            // Separate images into categories
            // PR images (pr-*) and build images (*-build) are excluded from retention
            // Build images will be cleaned up by docker image prune -af
            $prImages = $images->filter(fn ($image) => str_starts_with($image['tag'], 'pr-'));
            $regularImages = $images->filter(fn ($image) => ! str_starts_with($image['tag'], 'pr-') && ! str_ends_with($image['tag'], '-build'));

            // Always delete all PR images
            foreach ($prImages as $image) {
                $deleteCommand = "docker rmi {$image['image_ref']} 2>/dev/null || true";
                $deleteOutput = instant_remote_process([$deleteCommand], $server, false);
                $cleanupLog[] = [
                    'command' => $deleteCommand,
                    'output' => $deleteOutput ?? 'PR image removed or was in use',
                ];
            }

            // Filter out current running image from regular images and sort by creation date
            $sortedRegularImages = $regularImages
                ->filter(fn ($image) => $image['tag'] !== $currentTag)
                ->sortByDesc('created_at')
                ->values();

            // Keep only N images (imagesToKeep), delete the rest
            $imagesToDelete = $sortedRegularImages->skip($imagesToKeep);

            foreach ($imagesToDelete as $image) {
                $deleteCommand = "docker rmi {$image['image_ref']} 2>/dev/null || true";
                $deleteOutput = instant_remote_process([$deleteCommand], $server, false);
                $cleanupLog[] = [
                    'command' => $deleteCommand,
                    'output' => $deleteOutput ?? 'Image removed or was in use',
                ];
            }
        }

        return $cleanupLog;
    }
}
