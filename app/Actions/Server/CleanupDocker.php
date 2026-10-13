<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Support\Actions\UniqueUntilProcessingJobDecorator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Lorisleiva\Actions\Decorators\UniqueJobDecorator;
use RuntimeException;

class CleanupDocker implements ShouldBeUnique
{
    use AsAction;

    /**
     * Timeout of a queued cleanup, shared with DockerCleanupJob.
     */
    public const JOB_TIMEOUT = 600;

    /**
     * Time budget for all remote commands of one cleanup. It stays below JOB_TIMEOUT, so a
     * slow server makes the cleanup fail with an error instead of the worker killing the job.
     * A remote Docker prune that already started still finishes on the server.
     */
    public const REMOTE_COMMANDS_DEADLINE = 540;

    /**
     * Delay of a queued cleanup. Stop requests for one server in this window (for example a
     * bulk stop through the API) become one cleanup that runs after the batch.
     */
    public const QUEUED_DELAY = 60;

    /**
     * A resource stop does not queue a cleanup when a cleanup finished on the server in this
     * window. The scheduled cleanup still runs.
     */
    public const STOP_CLEANUP_COOLDOWN = 3600;

    public int $jobTries = 1;

    /**
     * Bounds how long a lost queued run (for example a killed worker) blocks new cleanups.
     */
    public int $jobUniqueFor = self::QUEUED_DELAY + 240;

    public int $jobTimeout = self::JOB_TIMEOUT;

    private int $deadline = 0;

    /**
     * Overlap lock key shared by DockerCleanupJob and queued CleanupDocker runs.
     */
    public static function overlapLockKey(Server $server): string
    {
        return 'docker-cleanup-'.$server->uuid;
    }

    /**
     * Lock lifetime: longer than the job timeout, so a new run cannot start while a timed-out
     * run is still finishing.
     */
    public static function overlapLockExpiresAfter(): int
    {
        return self::JOB_TIMEOUT + 60;
    }

    /**
     * At most one queued cleanup per server. The unique lock is released when a worker starts
     * the job, so a stop during a running cleanup queues one follow-up cleanup.
     */
    public static function makeUniqueJob(mixed ...$arguments): UniqueJobDecorator
    {
        return new UniqueUntilProcessingJobDecorator(static::class, ...$arguments);
    }

    public static function lastRunCacheKey(Server $server): string
    {
        return 'docker-cleanup-last-run-'.$server->uuid;
    }

    /**
     * Cleanup after a resource stop: skipped when a cleanup ran recently, and the queued run
     * only cleans when the disk usage is at or above the server cleanup threshold.
     */
    public static function dispatchAfterStop(Server $server): void
    {
        if (Cache::has(self::lastRunCacheKey($server))) {
            return;
        }

        static::dispatch($server, false, false, true);
    }

    public function getJobUniqueId(Server $server): string
    {
        return $server->uuid;
    }

    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue(maintenance_queue())->delay(self::QUEUED_DELAY);
    }

    /**
     * Only applies when dispatched as a job. ::run() inside DockerCleanupJob already holds
     * the lock and does not go through this middleware.
     */
    public function getJobMiddleware(Server $server): array
    {
        return [
            (new WithoutOverlapping(self::overlapLockKey($server)))
                ->shared()
                ->expireAfter(self::overlapLockExpiresAfter())
                ->dontRelease(),
        ];
    }

    public function handle(Server $server, bool $deleteUnusedVolumes = false, bool $deleteUnusedNetworks = false, bool $onlyAboveThreshold = false)
    {
        $this->deadline = now()->getTimestamp() + self::REMOTE_COMMANDS_DEADLINE;

        if ($onlyAboveThreshold) {
            $diskUsage = $server->getDiskUsage();
            if (is_numeric($diskUsage) && (int) $diskUsage < $server->settings->docker_cleanup_threshold) {
                return [];
            }
        }
        $helperImageVersion = getHelperVersion();
        $helperImage = coolifyHelperImage();
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
        // This ensures we clean up non-Coolify images while preserving rollback images and current helper image
        // Note: Only the current version is protected; old versions will be cleaned up by explicit commands below
        // We pass the version strings so all registry variants are protected (ghcr.io, docker.io, no prefix)
        $imagePruneCmd = $this->buildImagePruneCommand(
            $applicationImageRepos,
            $helperImageVersion
        );

        $commands = [
            'docker container prune -f --filter "label=coolify.managed=true" --filter "label!=coolify.proxy=true" --filter "label!=coolify.type=database" --filter "label!=coolify.type=application" --filter "label!=coolify.type=service"',
            $imagePruneCmd,
            'docker builder prune -af',
            // The prune fails on a stopped builder, so start it first.
            railpackBuilderHelperCommand(railpackBuildxMetadataVolume($server), $helperImageWithVersion, 'docker buildx inspect --bootstrap coolify-railpack >/dev/null 2>&1; docker buildx prune --builder coolify-railpack -af'),
            "docker images --filter before=$helperImageWithVersion --filter reference=$helperImage | grep $helperImage | awk '{print $3}' | xargs -r docker rmi -f",
            "docker images --filter before=$helperImageWithoutPrefixVersion --filter reference=$helperImageWithoutPrefix | grep $helperImageWithoutPrefix | awk '{print $3}' | xargs -r docker rmi -f",
        ];

        if ($deleteUnusedVolumes) {
            $commands[] = 'docker volume prune -af';
        }

        if ($deleteUnusedNetworks) {
            $commands[] = 'docker network prune -f';
        }

        foreach ($commands as $command) {
            $commandOutput = $this->runRemoteCommand($command, $server);
            if ($commandOutput !== null) {
                $cleanupLog[] = [
                    'command' => $command,
                    'output' => $commandOutput,
                ];
            }
        }

        Cache::put(self::lastRunCacheKey($server), true, self::STOP_CLEANUP_COOLDOWN);

        return $cleanupLog;
    }

    /**
     * Run one remote command with the time left before the cleanup deadline as its local
     * SSH timeout. Errors and timeouts of a single command are ignored, as before; once the
     * deadline has passed the cleanup stops and fails.
     */
    private function runRemoteCommand(string $command, Server $server): ?string
    {
        $remaining = $this->deadline - now()->getTimestamp();
        if ($remaining < 1) {
            throw new RuntimeException('Docker cleanup did not finish within '.self::REMOTE_COMMANDS_DEADLINE.' seconds. The remaining cleanup commands were not run.');
        }

        return instant_remote_process([$command], $server, false, timeout: $remaining);
    }

    /**
     * Build a docker image prune command that excludes application image repositories.
     *
     * Since docker image prune doesn't support excluding by repository name directly,
     * we use a shell script approach to delete unused images while preserving application images.
     */
    private function buildImagePruneCommand(
        $applicationImageRepos,
        string $helperImageVersion
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
        // Pattern: (^|/)coollabsio/coolify-helper:VERSION$
        $escapedHelperVersion = preg_replace('/([.\\\\+*?\[\]^$(){}|])/', '\\\\$1', $helperImageVersion);
        $infraExcludePattern = "(^|/)coollabsio/coolify-helper:{$escapedHelperVersion}$";

        // Delete unused images that:
        // - Are not application images (don't match app repos)
        // - Are not current Coolify helper image (any registry)
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
            "xargs -r -I {} sh -c 'docker inspect --format \"{{index .Config.Labels \\\"coolify.managed\\\"}}\" \"{}\" 2>/dev/null | grep -q true || docker rmi \"{}\" 2>/dev/null' || true";

        return implode(' && ', $commands);
    }

    /**
     * Remove old application images while the N most recent images of each application stay
     * for rollback. Uses a fixed number of remote commands, independent of the number of
     * applications on the server.
     */
    private function cleanupApplicationImages(Server $server, $applications = null): array
    {
        $cleanupLog = [];

        if ($applications === null) {
            $applications = $server->applications();
        }
        if ($applications->isEmpty()) {
            return $cleanupLog;
        }
        $applications->loadMissing('settings');

        $disableRetention = $server->settings->disable_application_image_retention ?? false;

        // Image of each container, keyed by container name
        $containerImages = collect(explode("\n", $this->runRemoteCommand("docker ps -a --format '{{.Names}}#{{.Image}}' 2>/dev/null || true", $server) ?? ''))
            ->filter()
            ->mapWithKeys(function (string $line) {
                [$name, $image] = array_pad(explode('#', $line, 2), 2, '');

                return [$name => $image];
            });

        $serverImages = collect(explode("\n", $this->runRemoteCommand("docker images --format '{{.Repository}}#{{.Tag}}#{{.CreatedAt}}' 2>/dev/null || true", $server) ?? ''))
            ->filter()
            ->map(function (string $line) {
                [$repository, $tag, $createdAt] = array_pad(explode('#', $line, 3), 3, '');

                return [
                    'repository' => $repository,
                    'tag' => $tag,
                    'created_at' => $createdAt,
                    'image_ref' => "{$repository}:{$tag}",
                ];
            })
            ->filter(fn ($image) => $image['tag'] !== '' && $image['tag'] !== '<none>');

        $imagesToDelete = collect();

        foreach ($applications as $application) {
            $imagesToKeep = $disableRetention ? 0 : ($application->settings->docker_images_to_keep ?? 2);
            $imageRepository = $application->docker_registry_image_name ?? $application->uuid;

            // Tag of the image of the running container (named after the application uuid)
            $currentTag = preg_match('/:([^:]+)$/', $containerImages->get($application->uuid, ''), $matches) ? $matches[1] : '';

            // Matches both uuid:tag and uuid_servicename:tag (Docker Compose with build), like
            // the docker images --filter reference='<repository>*' filter
            $images = $serverImages->filter(fn ($image) => str_starts_with($image['repository'], $imageRepository)
                && ! str_contains(substr($image['repository'], strlen($imageRepository)), '/'));

            if ($images->isEmpty()) {
                continue;
            }

            // Separate images into categories
            // PR images (pr-*) are always deleted
            // Build images (*-build) are cleaned up to match retained regular images
            $prImages = $images->filter(fn ($image) => str_starts_with($image['tag'], 'pr-'));
            $buildImages = $images->filter(fn ($image) => ! str_starts_with($image['tag'], 'pr-') && str_ends_with($image['tag'], '-build'));
            $regularImages = $images->filter(fn ($image) => ! str_starts_with($image['tag'], 'pr-') && ! str_ends_with($image['tag'], '-build'));

            // Filter out current running image from regular images and sort by creation date
            $sortedRegularImages = $regularImages
                ->filter(fn ($image) => $image['tag'] !== $currentTag)
                ->sortByDesc('created_at')
                ->values();

            // Clean up build images (-build suffix) that don't correspond to retained regular images
            // Build images are intermediate artifacts (e.g. Nixpacks) not used by running containers.
            // If a build is in progress, docker rmi will fail silently since the image is in use.
            $keptTags = $sortedRegularImages->take($imagesToKeep)->pluck('tag');
            if ($currentTag !== '') {
                $keptTags->push($currentTag);
            }

            $imagesToDelete = $imagesToDelete
                ->concat($prImages)
                ->concat($sortedRegularImages->skip($imagesToKeep))
                ->concat($buildImages->reject(fn ($image) => $keptTags->contains(preg_replace('/-build$/', '', $image['tag']))));
        }

        // Images in use fail silently; docker rmi continues with the next image
        foreach ($this->imageRemovalCommands($imagesToDelete->pluck('image_ref')->unique()) as $deleteCommand) {
            $deleteOutput = $this->runRemoteCommand($deleteCommand, $server);
            $cleanupLog[] = [
                'command' => $deleteCommand,
                'output' => $deleteOutput ?? 'Images removed or were in use',
            ];
        }

        return $cleanupLog;
    }

    /**
     * @param  Collection<int, string>  $imageRefs
     * @return Collection<int, string>
     */
    private function imageRemovalCommands(Collection $imageRefs): Collection
    {
        return $imageRefs
            ->chunk(100)
            ->map(fn (Collection $chunk) => 'docker rmi '.$chunk->map(fn (string $ref) => escapeshellarg($ref))->implode(' ').' 2>/dev/null || true')
            ->values();
    }
}
