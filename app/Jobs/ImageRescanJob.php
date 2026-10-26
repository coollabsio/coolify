<?php

namespace App\Jobs;

use App\Actions\ImageScan\RefreshCandidates;
use App\Actions\ImageScan\ScanImage;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ImageScanStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\ImageSbom;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\ImageScan\ResourceScans;
use App\Services\ImageScan\ScanLockBusy;
use App\Services\ImageScan\ScannerCommands;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Daily rescan of the images that run on a server (spec 4.5): updates the Grype database, runs Grype once per
 * running image ID, updates the scan rows of this server only, and applies the retention of spec 4.3.
 */
class ImageRescanJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Scans kept per resource besides the running image and the rollback list. */
    public const KEEP_LATEST_SCANS = 5;

    public const SBOM_UNUSED_DAYS = 30;

    /** SBOM files removed per run, so the command line stays short. The rest go the next day. */
    public const MAX_SBOM_DELETES = 500;

    private const TIMEOUT_DB_UPDATE = 960;

    private const TIMEOUT_DEFAULT = 120;

    /** The failed-day count of a server is kept this long after the last failure. */
    private const FAILURE_STATE_DAYS = 7;

    public $tries = ScanResourceImagesJob::MAX_ATTEMPTS;

    /** An unexpected error fails the job; only a busy scan lock retries it. */
    public $maxExceptions = 1;

    public $timeout = 36000;

    public $uniqueFor = 36000;

    public function __construct(public Server $server)
    {
        // Self-hosted Horizon works through 'high' first, so scans there would delay deployments.
        $this->onQueue(isCloud() ? maintenance_queue() : 'default');
    }

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    /**
     * Scanning is on for the server and allowed on the instance, and this instance can reach the server.
     */
    public static function canRunOn(Server $server): bool
    {
        return (bool) $server->settings?->is_image_scan_enabled
            && (bool) InstanceSettings::query()->whereKey(0)->value('is_image_scanning_allowed')
            && ! $server->isTransferredAway()
            && $server->isFunctional();
    }

    /**
     * Counts the days in a row on which the rescan of the server failed, for the digest (spec 8). A failed day is
     * one on which the running containers could not be listed or no image could be scanned. Success resets it.
     */
    public static function recordRescanResult(Server $server, bool $succeeded): void
    {
        $key = self::failuresCacheKey($server);
        if ($succeeded) {
            Cache::forget($key);

            return;
        }

        $state = Cache::get($key);
        $today = now()->toDateString();
        $state = match (true) {
            ! is_array($state) => ['days' => 1, 'last' => $today, 'notified' => false],
            ($state['last'] ?? null) === $today => $state,
            ($state['last'] ?? null) === now()->subDay()->toDateString() => [...$state, 'days' => (int) ($state['days'] ?? 0) + 1, 'last' => $today],
            default => ['days' => 1, 'last' => $today, 'notified' => false],
        };
        Cache::put($key, $state, now()->addDays(self::FAILURE_STATE_DAYS));
    }

    /**
     * Days in a row on which the rescan of the server failed, 0 after a success.
     */
    public static function failedDays(Server $server): int
    {
        $state = Cache::get(self::failuresCacheKey($server));

        return is_array($state) ? (int) ($state['days'] ?? 0) : 0;
    }

    public static function isFailureNotified(Server $server): bool
    {
        return (bool) data_get(Cache::get(self::failuresCacheKey($server)), 'notified', false);
    }

    public static function markFailureNotified(Server $server): void
    {
        $state = Cache::get(self::failuresCacheKey($server));
        if (is_array($state)) {
            Cache::put(self::failuresCacheKey($server), [...$state, 'notified' => true], now()->addDays(self::FAILURE_STATE_DAYS));
        }
    }

    private static function failuresCacheKey(Server $server): string
    {
        return "image-rescan:failures:{$server->id}";
    }

    public function handle(): void
    {
        $server = $this->server->fresh(['settings']);
        if (! $server instanceof Server || ! self::canRunOn($server)) {
            return;
        }

        $commands = new ScannerCommands($server, Str::lower(Str::random(24)));
        // Never sleeps on the scan lock, so a deploy gate gets it first. A busy lock runs the job again later and it
        // goes on where it stopped; the last attempt runs without the busy steps.
        $lastAttempt = $this->attempts() >= $this->tries;
        $progress = $this->progress();

        if (! $progress['database']) {
            if (! $this->updateDatabase($server, $commands) && ! $lastAttempt) {
                $this->retryLater($progress);

                return;
            }
            $progress['database'] = true;
        }

        $listing = $this->runningContainers($server, $commands);
        if ($listing === null) {
            self::recordRescanResult($server, false);
            $this->forgetProgress();

            return;
        }

        [$targets, $runningRefs] = $this->targets($server, $listing['containers']);
        foreach ($targets as $imageId => $imageTargets) {
            if (in_array($imageId, $progress['done'], true)) {
                continue;
            }

            try {
                $scans = ScanImage::make()->rescan($server, $imageId, $imageTargets, 0, retryWhenBusy: ! $lastAttempt);
                if (! $lastAttempt && $scans !== [] && collect($scans)->every(fn (ImageScan $scan): bool => ScanImage::lockWasBusy($scan))) {
                    $this->retryLater($progress);

                    return;
                }
                $progress['scanned'] += count($scans);
                $progress['completed'] += count(array_filter($scans, fn (ImageScan $scan): bool => $scan->status === ImageScanStatus::Completed));
                $this->refreshCandidates($scans);
            } catch (Throwable $e) {
                $progress['errors']++;
                report($e);
            }
            $progress['done'][] = $imageId;
        }
        self::recordRescanResult($server, $progress['completed'] > 0 || ($progress['scanned'] === 0 && $progress['errors'] === 0));
        $this->forgetProgress();
        $this->refreshCountsAfterEndedIgnores($server);

        // A cut list would make running images look stopped.
        if (! $listing['truncated']) {
            $this->cleanUp($server, $commands, $targets, $runningRefs);
        }
    }

    /**
     * An ignore that ended counts its findings again: the stored counts of the resources it covered are written again,
     * also for images that no longer run. Once per team and day, for ignores that ended in the last two days.
     */
    private function refreshCountsAfterEndedIgnores(Server $server): void
    {
        try {
            $ended = ImageScanIgnore::query()
                ->where('team_id', $server->team_id)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->where('expires_at', '>', now()->subDays(2))
                ->get();
            if ($ended->isEmpty() || ! Cache::add("image-scan:ended-ignores:{$server->team_id}:".now()->toDateString(), true, now()->addDay())) {
                return;
            }

            if ($ended->contains(fn (ImageScanIgnore $ignore): bool => $ignore->resource_id === null)) {
                RefreshImageScanSummariesJob::dispatch((int) $server->team_id);

                return;
            }

            $ended->map(fn (ImageScanIgnore $ignore) => $ignore->resource)
                ->filter(fn ($resource): bool => $resource instanceof Model)
                ->unique(fn (Model $resource): string => $resource->getMorphClass().':'.$resource->getKey())
                ->each(fn (Model $resource) => ResourceScans::refreshSummaries(ResourceScans::currentFor($resource)));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * What earlier attempts of this job did: database updated, image IDs rescanned, and the counts of the result.
     *
     * @return array{database: bool, done: list<string>, scanned: int, completed: int, errors: int}
     */
    private function progress(): array
    {
        $empty = ['database' => false, 'done' => [], 'scanned' => 0, 'completed' => 0, 'errors' => 0];
        $key = $this->progressCacheKey();
        $stored = $key === null ? null : Cache::get($key);

        return is_array($stored) ? [...$empty, ...$stored] : $empty;
    }

    /**
     * @param  array{database: bool, done: list<string>, scanned: int, completed: int, errors: int}  $progress
     */
    private function retryLater(array $progress): void
    {
        $key = $this->progressCacheKey();
        if ($key !== null) {
            Cache::put($key, $progress, now()->addHours(12));
        }
        $this->release(ScanResourceImagesJob::lockRetryDelay($this->attempts()));
    }

    private function forgetProgress(): void
    {
        $key = $this->progressCacheKey();
        if ($key !== null) {
            Cache::forget($key);
        }
    }

    /**
     * A released job runs again from its stored payload, which keeps its UUID.
     */
    private function progressCacheKey(): ?string
    {
        $uuid = $this->job?->uuid();

        return $uuid === null ? null : "image-rescan:progress:{$uuid}";
    }

    /**
     * Looks for newer versions of each rescanned image that has a package fix at the alert level (spec 4.5, 7.1).
     * The rescan released the lock; each candidate scan takes it again. Runs here, one image after the other, under
     * the unique lock of RefreshCandidatesJob: a queued or running job of the same scan does the check instead. When
     * the scan lock is busy, the check moves to a queued job that retries later.
     *
     * @param  list<ImageScan>  $scans
     */
    private function refreshCandidates(array $scans): void
    {
        $uniqueLock = new UniqueLock(Cache::store());

        foreach ($scans as $scan) {
            try {
                if (! RefreshCandidates::shouldRefresh($scan)) {
                    continue;
                }
                $job = new RefreshCandidatesJob($scan);
                if (! $uniqueLock->acquire($job)) {
                    continue;
                }
                $busy = false;
                try {
                    RefreshCandidates::run($scan, 0, retryWhenBusy: true);
                } catch (ScanLockBusy) {
                    $busy = true;
                } finally {
                    $uniqueLock->release($job);
                }
                if ($busy) {
                    RefreshCandidatesJob::dispatch($scan);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Retention and SBOM deletion under the scan lock, so a scan that starts meanwhile keeps its SBOM and rows. When
     * the lock is busy they skip a day.
     *
     * @param  array<string, list<array{0: Model, 1: string}>>  $targets
     * @param  array<string, list<string>>  $runningRefs
     */
    private function cleanUp(Server $server, ScannerCommands $commands, array $targets, array $runningRefs): void
    {
        $lock = ScanImage::serverLock($server);
        if (! ScanImage::acquireServerLock($lock, $server, 0)) {
            return;
        }

        try {
            $this->applyRetention($server, $targets, $runningRefs);
            $this->deleteUnusedSboms($server, $commands);
        } catch (Throwable $e) {
            report($e);
        } finally {
            $lock->release();
        }
    }

    /**
     * Updates the database under the scan lock, so no scan reads it while it changes. A failed update keeps the
     * existing database; the scans report when it is too old. False when the lock is busy.
     */
    private function updateDatabase(Server $server, ScannerCommands $commands): bool
    {
        $lock = ScanImage::serverLock($server);
        if (! ScanImage::acquireServerLock($lock, $server, 0)) {
            return false;
        }

        $url = InstanceSettings::get()->image_scan_db_url;

        try {
            $output = instant_remote_process($commands->dbUpdate(is_string($url) && trim($url) !== '' ? trim($url) : null), $server, throwError: false, timeout: self::TIMEOUT_DB_UPDATE);
            if ((ScannerCommands::parseOutput($output)['exit_codes']['db-update'] ?? null) === 0) {
                PrepareImageScannerJob::storeDetails($server, $commands);
            }
        } catch (Throwable $e) {
            report($e);
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * The running containers, or null when they could not be listed. `truncated`: the list was cut at the output cap.
     *
     * @return array{containers: list<array{id: string, ref: string, labels: Collection<string, mixed>}>, truncated: bool}|null
     */
    private function runningContainers(Server $server, ScannerCommands $commands): ?array
    {
        try {
            $parsed = ScannerCommands::parseOutput(instant_remote_process($commands->runningContainers(), $server, throwError: false, timeout: self::TIMEOUT_DEFAULT));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        if (($parsed['exit_codes']['containers'] ?? null) !== 0) {
            return null;
        }

        $payload = $parsed['payload'];
        $truncated = strlen($payload) > ScannerCommands::OUTPUT_LIMIT;
        if ($truncated) {
            $payload = substr($payload, 0, (int) strrpos(substr($payload, 0, ScannerCommands::OUTPUT_LIMIT), "\n"));
        }

        $containers = [];
        foreach (explode("\n", $payload) as $line) {
            $container = json_decode(trim($line), true);
            if (! is_array($container)
                || ! is_string($container['id'] ?? null)
                || preg_match('/\Asha256:[a-f0-9]{64}\z/', $container['id']) !== 1) {
                continue;
            }

            $containers[] = [
                'id' => $container['id'],
                'ref' => is_string($container['ref'] ?? null) ? $container['ref'] : '',
                'labels' => collect(is_array($container['labels'] ?? null) ? $container['labels'] : [])
                    ->filter(fn ($value, $key) => is_string($key) && is_scalar($value))
                    ->map(fn ($value) => (string) $value),
            ];
        }

        return ['containers' => $containers, 'truncated' => $truncated];
    }

    /**
     * The resources of this server that run each image ID, with the image ref of their container, and the image
     * refs each resource runs. Containers of resources on other servers, of previews that no longer exist, and of
     * no resource are skipped.
     *
     * @param  list<array{id: string, ref: string, labels: Collection<string, mixed>}>  $containers
     * @return array{0: array<string, list<array{0: Model, 1: string}>>, 1: array<string, list<string>>}
     */
    private function targets(Server $server, array $containers): array
    {
        $applications = $server->applications();
        $databases = $server->databases();
        $services = $server->services()->with(['applications', 'databases'])->get();
        $targets = [];
        $runningRefs = [];

        foreach ($containers as $container) {
            $labels = $container['labels'];
            if (filter_var($labels->get('com.docker.compose.oneoff'), FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $resource = match (true) {
                isContainerOfType($labels, 'application') => $this->applicationOwner($applications, $labels),
                isContainerOfType($labels, 'service') => resolveServiceContainerOwner($services, $labels)[2],
                isContainerOfType($labels, 'database') => resolveContainerOwner($databases, $labels, 'database'),
                default => null,
            };
            if (! $resource instanceof Model) {
                continue;
            }

            $key = $resource->getMorphClass().':'.$resource->getKey();
            $targets[$container['id']][$key] ??= [$resource, $container['ref'] !== '' ? $container['ref'] : $container['id']];
            $runningRefs[$key][] = $container['ref'];
        }

        return [array_map('array_values', $targets), $runningRefs];
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @param  Collection<string, mixed>  $labels
     */
    private function applicationOwner(Collection $applications, Collection $labels): ?Application
    {
        $application = resolveContainerOwner($applications, $labels, 'application');
        $pullRequestId = (int) $labels->get('coolify.pullRequestId', 0);
        if (! $application instanceof Application || $pullRequestId === 0) {
            return $application instanceof Application ? $application : null;
        }

        $previewExists = ApplicationPreview::query()
            ->where('application_id', $application->id)
            ->where('pull_request_id', $pullRequestId)
            ->exists();

        return $previewExists ? $application : null;
    }

    /**
     * Keeps the scans of running images, of each application's rollback list, and the latest scans per resource;
     * deletes scans of previews that no longer exist (spec 4.3). Only rows of this server.
     *
     * @param  array<string, list<array{0: Model, 1: string}>>  $targets
     * @param  array<string, list<string>>  $runningRefs
     */
    private function applyRetention(Server $server, array $targets, array $runningRefs): void
    {
        $running = [];
        foreach ($targets as $imageId => $imageTargets) {
            foreach ($imageTargets as [$resource]) {
                $running[$resource->getMorphClass().':'.$resource->getKey().':'.$imageId] = true;
            }
        }

        ImageScan::query()
            ->where('server_id', $server->id)
            ->select(['resource_type', 'resource_id'])
            ->distinct()
            ->get()
            ->each(function (ImageScan $group) use ($server, $running, $runningRefs) {
                $scans = ImageScan::query()
                    ->where('server_id', $server->id)
                    ->where('resource_type', $group->resource_type)
                    ->where('resource_id', $group->resource_id)
                    ->with('deploymentQueue:id,pull_request_id')
                    ->orderByRaw('coalesce(scanned_at, updated_at) desc')
                    ->orderByDesc('id')
                    ->get(['id', 'resource_type', 'resource_id', 'image_id', 'image_ref', 'deployment_queue_id', 'scanned_at', 'updated_at']);

                $application = $group->resource_type === (new Application)->getMorphClass()
                    ? Application::withTrashed()->with('settings')->find($group->resource_id)
                    : null;
                $rollback = $application !== null
                    ? $this->rollbackList($server, $application, $runningRefs[$group->resource_type.':'.$group->resource_id] ?? [])
                    : ['deployments' => [], 'refs' => []];
                $previews = $application !== null ? $this->existingPreviews($application) : [];

                $delete = [];
                foreach ($scans as $index => $scan) {
                    $pullRequestId = $this->previewPullRequestId($scan, $application);
                    $previewGone = $pullRequestId !== null && ! in_array($pullRequestId, $previews, true);
                    $isRunning = isset($running[$scan->resource_type.':'.$scan->resource_id.':'.$scan->image_id]);
                    $keep = $index < self::KEEP_LATEST_SCANS
                        || in_array((int) $scan->deployment_queue_id, $rollback['deployments'], true)
                        || in_array($scan->image_ref, $rollback['refs'], true);
                    // A row is per image, so a preview and production can share it: a running image always stays.
                    if (! $isRunning && ($previewGone || ! $keep)) {
                        $delete[] = $scan->id;
                    }
                }

                if ($delete !== []) {
                    ImageScan::query()->where('server_id', $server->id)->whereKey($delete)->delete();
                }
            });
    }

    /**
     * The deployments and image refs of the images that CleanupDocker keeps for rollbacks besides the running ones:
     * the latest `docker_images_to_keep` finished deployments whose image is not running, none when the server
     * turned off image retention.
     *
     * @param  list<string>  $runningRefs
     * @return array{deployments: list<int>, refs: list<string>}
     */
    private function rollbackList(Server $server, Application $application, array $runningRefs): array
    {
        $keep = $server->settings?->disable_application_image_retention ? 0 : max(0, (int) ($application->settings?->docker_images_to_keep ?? 2));
        if ($keep === 0) {
            return ['deployments' => [], 'refs' => []];
        }

        $deployments = ApplicationDeploymentQueue::query()
            ->where('application_id', (string) $application->id)
            ->where('pull_request_id', 0)
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'commit']);

        $repository = $application->docker_registry_image_name ?: $application->uuid;
        $commits = $deployments->pluck('commit')
            ->filter(fn ($commit) => filled($commit))
            ->unique()
            ->reject(fn (string $commit) => in_array($repository.':'.substr($commit, 0, 128), $runningRefs, true))
            ->take($keep)
            ->values();

        return [
            'deployments' => $deployments->whereIn('commit', $commits->all())->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'refs' => $commits->map(fn (string $commit) => $repository.':'.substr($commit, 0, 128))->all(),
        ];
    }

    /**
     * @return list<int>
     */
    private function existingPreviews(Application $application): array
    {
        return ApplicationPreview::query()
            ->where('application_id', $application->id)
            ->pluck('pull_request_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The pull request of a preview scan: from its deployment, else from the preview tag of its image
     * (`{image}:pr-{id}-{commit}`). Null for a scan of the application itself.
     */
    private function previewPullRequestId(ImageScan $scan, ?Application $application): ?int
    {
        if ($application === null) {
            return null;
        }

        $fromDeployment = (int) ($scan->deploymentQueue?->pull_request_id ?? 0);
        if ($fromDeployment > 0) {
            return $fromDeployment;
        }

        $repository = preg_quote($application->docker_registry_image_name ?: $application->uuid, '/');
        if (preg_match('/\A'.$repository.':pr-(\d+)-/', (string) $scan->image_ref, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * Deletes SBOMs of this server that no scan uses and that were not used for 30 days, files first.
     */
    private function deleteUnusedSboms(Server $server, ScannerCommands $commands): void
    {
        $sboms = ImageSbom::query()
            ->where('server_id', $server->id)
            ->where('last_used_at', '<', now()->subDays(self::SBOM_UNUSED_DAYS))
            ->whereDoesntHave('scans')
            ->orderBy('id')
            ->limit(self::MAX_SBOM_DELETES)
            ->get(['id', 'image_id'])
            ->filter(fn (ImageSbom $sbom) => preg_match('/\Asha256:[a-f0-9]{64}\z/', (string) $sbom->image_id) === 1);
        if ($sboms->isEmpty()) {
            return;
        }

        $output = instant_remote_process($commands->deleteSboms($sboms->pluck('image_id')->values()->all()), $server, throwError: false, timeout: self::TIMEOUT_DEFAULT);
        if ((ScannerCommands::parseOutput($output)['exit_codes']['sbom-delete'] ?? null) !== 0) {
            return;
        }

        ImageSbom::query()
            ->where('server_id', $server->id)
            ->whereKey($sboms->pluck('id')->all())
            ->whereDoesntHave('scans')
            ->delete();
    }
}
