<?php

namespace App\Actions\ImageScan;

use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanStatus;
use App\Events\ImageScanUpdated;
use App\Jobs\RefreshCandidatesJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ImageSbom;
use App\Models\ImageScan;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\GrypeReportParser;
use App\Services\ImageScan\IgnoreMatcher;
use App\Services\ImageScan\ImageRef;
use App\Services\ImageScan\ImageScanGate;
use App\Services\ImageScan\ImageScanPolicyResolver;
use App\Services\ImageScan\Package;
use App\Services\ImageScan\ResolvedImageScanPolicy;
use App\Services\ImageScan\ScanFailed;
use App\Services\ImageScan\ScannerCommands;
use App\Services\ImageScan\ScanNotifications;
use App\Services\ImageScan\ScanSummary;
use App\Services\ImageScan\SyftPackageParser;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Scans one local image on a server with Syft and Grype and stores the result for a resource (spec 4.1).
 *
 * A scan row exists only for an image ID that was inspected. Progress lives in the cache
 * (`progressCacheKey()`), the last failure too (`lastErrorCacheKey()`).
 */
class ScanImage
{
    use AsAction;

    public const LOCK_TTL = 2700;

    public const LAST_ERROR_TTL_DAYS = 7;

    /** ScannerCommands budgets plus 60 s. */
    private const TIMEOUT_DEFAULT = 120;

    private const TIMEOUT_DB_UPDATE = 960;

    private const TIMEOUT_SBOM = 1260;

    private const TIMEOUT_GRYPE = 360;

    private const DB_MAX_AGE_DAYS = 5;

    private const DISK_RESERVE_BYTES = 1073741824;

    /** Exit codes of `timeout`: stopped, or killed after the grace period. */
    private const EXIT_TIMEOUT = [124, 137];

    /** Exit code of a failed `docker run`, for example a missing bind mount source. */
    private const EXIT_DOCKER_RUN = 125;

    private string $runId = '';

    private CarbonImmutable $startedAt;

    /**
     * Never throws for scan problems. Callers such as the deploy gate must use the returned scan: on a failure it is an
     * unsaved scan with status Failed and an error code, and a completed scan row of the same image keeps its results.
     * A Failed row is saved only when no row exists yet for the inspected image.
     *
     * @param  ?string  $baseImageRef  The base image of the final stage of a Dockerfile build (spec 7.4), stored on the scan.
     */
    public function handle(Server $server, Model $resource, string $imageRef, ?ApplicationDeploymentQueue $deployment = null, int $lockWaitSeconds = 0, ?string $baseImageRef = null): ImageScan
    {
        $this->runId = Str::lower(Str::random(24));
        $this->startedAt = CarbonImmutable::now();
        $policy = ImageScanPolicyResolver::for($resource, $server);
        $context = ['server' => (string) $server->name];

        if ($server->isTransferredAway() || ! $policy->scanEnabled) {
            return $this->unsavedFailure($server, $resource, $imageRef, null, ImageScanErrorCode::ScanningOff, $context);
        }

        try {
            ImageRef::parse($imageRef);
        } catch (InvalidArgumentException) {
            return $this->failBeforeRow($server, $resource, $imageRef, null, ImageScanErrorCode::Unknown, $context);
        }

        $lock = self::serverLock($server);
        if (! $this->acquire($lock, $lockWaitSeconds)) {
            return $this->failBeforeRow($server, $resource, $imageRef, null, ImageScanErrorCode::LockBusy, $context);
        }

        try {
            $scan = $this->scan($server, $resource, $imageRef, $deployment, $policy, $context, self::validBaseImageRef($baseImageRef));
        } finally {
            $lock->release();
        }

        $this->notifyAfterScan($scan);
        $this->queueCandidateCheck($scan);

        return $scan;
    }

    /**
     * Records what the notifications covered and alerts for a new known exploited finding (spec 8). The alert is a
     * queued notification, so a deploy never waits for it.
     */
    private function notifyAfterScan(ImageScan $scan): void
    {
        try {
            ScanNotifications::afterScan($scan);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Looks for a newer version that fixes the findings in a queued job, after the lock is released, so a deploy never waits for it (spec 7.1).
     */
    private function queueCandidateCheck(ImageScan $scan): void
    {
        try {
            if (RefreshCandidates::shouldRefresh($scan)) {
                RefreshCandidatesJob::dispatch($scan);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Rescans one local image for every resource that runs it on the server (spec 4.5): one SBOM and one Grype run,
     * then the row of each resource, with its own summary. Never throws for scan problems; a failure is recorded per
     * resource like in handle(), and completed rows keep their results.
     *
     * @param  list<array{0: Model, 1: string}>  $targets  Each resource with the image ref of its container.
     * @return list<ImageScan>
     */
    public function rescan(Server $server, string $imageId, array $targets, int $lockWaitSeconds = 0): array
    {
        $this->runId = Str::lower(Str::random(24));
        $this->startedAt = CarbonImmutable::now();
        $context = ['server' => (string) $server->name];

        if ($server->isTransferredAway()) {
            return [];
        }

        $targets = array_values(array_filter(
            array_map(fn (array $target): array => [...$target, ImageScanPolicyResolver::for($target[0], $server)], $targets),
            fn (array $target): bool => $target[2]->scanEnabled,
        ));
        if ($targets === []) {
            return [];
        }

        if (preg_match('/\Asha256:[a-f0-9]{64}\z/', $imageId) !== 1) {
            return array_map(fn (array $target) => $this->failBeforeRow($server, $target[0], $target[1], null, ImageScanErrorCode::Unknown, $context), $targets);
        }

        $lock = self::serverLock($server);
        if (! $this->acquire($lock, $lockWaitSeconds)) {
            return array_map(fn (array $target) => $this->failBeforeRow($server, $target[0], $target[1], $imageId, ImageScanErrorCode::LockBusy, $context), $targets);
        }

        try {
            $scans = $this->scanShared($server, $imageId, $targets, $context);
        } finally {
            $lock->release();
        }

        foreach ($scans as $scan) {
            $this->notifyAfterScan($scan);
        }

        return $scans;
    }

    /**
     * One scan or scan data change at a time per server (spec 4.1).
     */
    public static function serverLock(Server $server): Lock
    {
        return Cache::lock("image-scan:{$server->id}", self::LOCK_TTL, Str::random(40));
    }

    public static function progressCacheKey(Model $resource): string
    {
        return "image-scan:progress:{$resource->getMorphClass()}:{$resource->getKey()}";
    }

    public static function lastErrorCacheKey(Model $resource): string
    {
        return "image-scan:last-error:{$resource->getMorphClass()}:{$resource->getKey()}";
    }

    /**
     * A base image reference that ImageRef accepts, else null.
     */
    private static function validBaseImageRef(?string $ref): ?string
    {
        if ($ref === null || mb_strlen($ref) > 512) {
            return null;
        }

        try {
            ImageRef::parse($ref);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $ref;
    }

    /**
     * @param  array{server: string}  $context
     */
    private function scan(Server $server, Model $resource, string $imageRef, ?ApplicationDeploymentQueue $deployment, ResolvedImageScanPolicy $policy, array $context, ?string $baseImageRef = null): ImageScan
    {
        $commands = new ScannerCommands($server, $this->runId);
        $isBaseline = ! $this->resourceScans($resource)->where('status', ImageScanStatus::Completed)->exists();
        $image = null;

        try {
            $this->progress($resource, 'database');
            $databaseBuiltAt = $this->databaseBuiltAt($commands);
            $this->progress($resource, 'inspect');
            $image = $this->inspect($commands, $imageRef);
            $this->progress($resource, 'sbom');
            [$sbom, $grype] = $this->sbomAndReport($commands, [$resource], $imageRef, $image);

            return $this->complete($server, $resource, $imageRef, $deployment, $policy, $sbom, $this->report($sbom, $grype), $image, $databaseBuiltAt, $isBaseline, $baseImageRef);
        } catch (ScanFailed $e) {
            $errorContext = [...$context, ...$e->context];

            return $image === null
                ? $this->failBeforeRow($server, $resource, $imageRef, null, $e->errorCode, $errorContext)
                : $this->failAfterInspect($server, $resource, $imageRef, $deployment, $image, $e->errorCode, $errorContext);
        } catch (Throwable $e) {
            $this->rememberError($resource, ImageScanErrorCode::Unknown, $context);

            throw $e;
        } finally {
            Cache::forget(self::progressCacheKey($resource));
            instant_remote_process($commands->cleanup(), $server, throwError: false, timeout: self::TIMEOUT_DEFAULT);
        }
    }

    /**
     * @param  list<array{0: Model, 1: string, 2: ResolvedImageScanPolicy}>  $targets
     * @param  array{server: string}  $context
     * @return list<ImageScan>
     */
    private function scanShared(Server $server, string $imageId, array $targets, array $context): array
    {
        $commands = new ScannerCommands($server, $this->runId);
        $resources = array_map(fn (array $target): Model => $target[0], $targets);
        $isBaseline = array_map(fn (Model $resource): bool => ! $this->resourceScans($resource)->where('status', ImageScanStatus::Completed)->exists(), $resources);
        $image = null;

        try {
            $this->progressAll($resources, 'database');
            $databaseBuiltAt = $this->databaseBuiltAt($commands);
            $this->progressAll($resources, 'inspect');
            // By ID, not by a tag that can point to another image by now.
            $image = $this->inspect($commands, $imageId);
            if ($image['id'] !== $imageId) {
                $image = null;

                throw new ScanFailed(ImageScanErrorCode::ImageMissing);
            }
            $this->progressAll($resources, 'sbom');
            [$sbom, $grype] = $this->sbomAndReport($commands, $resources, $imageId, $image);
            $report = $this->report($sbom, $grype);

            return array_map(
                fn (array $target, int $index) => $this->complete($server, $target[0], $target[1], null, $target[2], $sbom, $report, $image, $databaseBuiltAt, $isBaseline[$index]),
                $targets,
                array_keys($targets),
            );
        } catch (ScanFailed $e) {
            $errorContext = [...$context, ...$e->context];

            return array_map(fn (array $target) => $image === null
                ? $this->failBeforeRow($server, $target[0], $target[1], null, $e->errorCode, $errorContext)
                : $this->failAfterInspect($server, $target[0], $target[1], null, $image, $e->errorCode, $errorContext), $targets);
        } catch (Throwable $e) {
            foreach ($resources as $resource) {
                $this->rememberError($resource, ImageScanErrorCode::Unknown, $context);
            }

            throw $e;
        } finally {
            foreach ($resources as $resource) {
                Cache::forget(self::progressCacheKey($resource));
            }
            instant_remote_process($commands->cleanup(), $server, throwError: false, timeout: self::TIMEOUT_DEFAULT);
        }
    }

    /**
     * @return Builder<ImageScan>
     */
    private function resourceScans(Model $resource): Builder
    {
        return ImageScan::query()
            ->where('resource_type', $resource->getMorphClass())
            ->where('resource_id', $resource->getKey());
    }

    private function acquire(Lock $lock, int $lockWaitSeconds): bool
    {
        if ($lockWaitSeconds <= 0) {
            return (bool) $lock->get();
        }

        try {
            return (bool) $lock->block($lockWaitSeconds);
        } catch (LockTimeoutException) {
            return false;
        }
    }

    private function progress(Model $resource, string $stage): void
    {
        Cache::put(self::progressCacheKey($resource), [
            'run_id' => $this->runId,
            'stage' => $stage,
            'started_at' => $this->startedAt->toIso8601String(),
        ], self::LOCK_TTL);
        $this->broadcast($resource, $this->runId);
    }

    /**
     * @param  list<Model>  $resources
     */
    private function progressAll(array $resources, string $stage): void
    {
        foreach ($resources as $resource) {
            $this->progress($resource, $stage);
        }
    }

    /**
     * Updates a missing database once. Grype refuses a database older than 5 days.
     */
    private function databaseBuiltAt(ScannerCommands $commands): CarbonImmutable
    {
        $builtAt = $this->databaseStatus($commands);

        if ($builtAt === null) {
            $this->updateDatabase($commands);
            $builtAt = $this->databaseStatus($commands) ?? throw new ScanFailed(ImageScanErrorCode::DbMissing);
        }

        if ($builtAt->lt(now()->subDays(self::DB_MAX_AGE_DAYS))) {
            throw new ScanFailed(ImageScanErrorCode::DbTooOld, ['n' => (int) floor($builtAt->diffInDays(now()))]);
        }

        return $builtAt;
    }

    private function updateDatabase(ScannerCommands $commands): void
    {
        $url = InstanceSettings::get()->image_scan_db_url;

        try {
            $update = $commands->dbUpdate(is_string($url) && trim($url) !== '' ? trim($url) : null);
        } catch (InvalidArgumentException) {
            return;
        }

        instant_remote_process($update, $commands->server, throwError: false, timeout: self::TIMEOUT_DB_UPDATE);
    }

    private function databaseStatus(ScannerCommands $commands): ?CarbonImmutable
    {
        $result = $this->runStep($commands->server, $commands->dbStatus(), 'db-status', self::TIMEOUT_DEFAULT);
        if (in_array($result['code'], self::EXIT_TIMEOUT, true)) {
            throw new ScanFailed(ImageScanErrorCode::Timeout);
        }

        $status = $result['code'] === 0 ? json_decode($result['payload'], true) : null;
        $built = is_array($status) && ($status['valid'] ?? false) === true ? ($status['built'] ?? null) : null;

        if (! is_string($built) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/', $built) !== 1) {
            return null;
        }

        return CarbonImmutable::parse($built);
    }

    /**
     * @return array{id: string, hex: string, repo_digest: ?string, platform: string, size: int}
     */
    private function inspect(ScannerCommands $commands, string $imageRef): array
    {
        $result = $this->runStep($commands->server, $commands->inspect($imageRef), 'inspect', self::TIMEOUT_DEFAULT);
        if ($result['code'] !== 0) {
            throw new ScanFailed(ImageScanErrorCode::ImageMissing);
        }

        $parts = explode('|', trim($result['payload']));
        if (count($parts) !== 4
            || preg_match('/\Asha256:([a-f0-9]{64})\z/', $parts[0], $id) !== 1
            || preg_match('#\A[a-z0-9]+/[a-z0-9_]+\z#', $parts[2]) !== 1
            || preg_match('/\A\d{1,18}\z/', $parts[3]) !== 1) {
            throw new ScanFailed(ImageScanErrorCode::Unknown);
        }

        $repoDigest = Str::afterLast($parts[1], '@');

        return [
            'id' => $parts[0],
            'hex' => $id[1],
            'repo_digest' => str_contains($parts[1], '@') && ImageRef::isValidDigest($repoDigest) ? $repoDigest : null,
            'platform' => $parts[2],
            'size' => (int) $parts[3],
        ];
    }

    /**
     * `docker save` and the SBOM need image size × 2 + 1 GiB on the Docker root and on the scan directory.
     * Candidate scans from a registry use the same rule (Syft writes the layers to the scratch directory).
     *
     * @throws ScanFailed
     */
    public static function checkDiskSpace(ScannerCommands $commands, int $imageSize): void
    {
        $output = instant_remote_process($commands->diskFree(), $commands->server, throwError: false, timeout: self::TIMEOUT_DEFAULT);
        if (preg_match('/(\d+) +(\d+)\s*\z/', (string) $output, $free) !== 1) {
            throw new ScanFailed(ImageScanErrorCode::Unknown);
        }

        $needed = $imageSize * 2 + self::DISK_RESERVE_BYTES;
        if ((int) $free[1] < $needed || (int) $free[2] < $needed) {
            throw new ScanFailed(ImageScanErrorCode::DiskSpace, ['n' => (int) ceil($needed / self::DISK_RESERVE_BYTES)]);
        }
    }

    /**
     * Reuses the SBOM of the image on this server. When its file is gone, Grype cannot mount it: the file is
     * created once more and the row is updated in place (scans of other resources keep their link).
     *
     * @param  list<Model>  $resources
     * @param  array{id: string, hex: string, repo_digest: ?string, platform: string, size: int}  $image
     * @return array{0: ImageSbom, 1: array{code: int, size: ?int, payload: string, exit_codes: array<string, int>}}
     */
    private function sbomAndReport(ScannerCommands $commands, array $resources, string $imageRef, array $image): array
    {
        $sbom = ImageSbom::query()->where('server_id', $commands->server->id)->where('image_id', $image['id'])->first();

        if ($sbom !== null) {
            $this->progressAll($resources, 'vulnerabilities');
            $grype = $this->runStep($commands->server, $commands->grype($image['hex']), 'grype', self::TIMEOUT_GRYPE);

            if ($grype['code'] !== self::EXIT_DOCKER_RUN) {
                $this->assertSucceeded($grype['code']);
                $sbom->forceFill(['last_used_at' => now()])->save();

                return [$sbom, $grype];
            }

            $this->progressAll($resources, 'sbom');
        }

        $sbom = $this->createSbom($commands, $imageRef, $image, $sbom);
        $this->progressAll($resources, 'vulnerabilities');
        $grype = $this->runStep($commands->server, $commands->grype($image['hex']), 'grype', self::TIMEOUT_GRYPE);
        $this->assertSucceeded($grype['code']);

        return [$sbom, $grype];
    }

    /**
     * @param  array{id: string, hex: string, repo_digest: ?string, platform: string, size: int}  $image
     */
    private function createSbom(ScannerCommands $commands, string $imageRef, array $image, ?ImageSbom $sbom): ImageSbom
    {
        self::checkDiskSpace($commands, $image['size']);

        $result = $this->runStep($commands->server, $commands->sbomFromLocal($imageRef, $image['hex']), 'save', self::TIMEOUT_SBOM);
        $this->assertSucceeded($result['code']);
        $this->assertSucceeded($result['exit_codes']['sbom'] ?? null);

        $packages = $this->runStep($commands->server, $commands->packages($image['hex']), 'packages', self::TIMEOUT_GRYPE);
        $this->assertSucceeded($packages['code']);
        [$payload, $cut] = $this->readPayload($packages);
        $parsed = (new SyftPackageParser)->parse($payload);

        $values = [
            'platform' => $image['platform'],
            'packages' => array_map(fn (Package $package) => $package->toArray(), $parsed['packages']),
            'packages_truncated' => $parsed['truncated'] || $cut,
            'last_used_at' => now(),
        ];

        if ($sbom !== null) {
            $sbom->forceFill($values)->save();

            return $sbom;
        }

        return ImageSbom::query()->create([
            'server_id' => $commands->server->id,
            'image_id' => $image['id'],
            'base_layer_ids' => [],
            ...$values,
        ]);
    }

    /**
     * Findings of the Grype output, with their layer, sorted by risk.
     *
     * @param  array{code: int, size: ?int, payload: string, exit_codes: array<string, int>}  $grype
     * @return array{findings: list<Finding>, eol: ?array{name: string, version: string}, truncated: bool}
     */
    private function report(ImageSbom $sbom, array $grype): array
    {
        [$payload, $cut] = $this->readPayload($grype);
        $report = (new GrypeReportParser)->parse($payload);
        $findings = $this->withLayers($report['findings'], $sbom);
        usort($findings, fn (Finding $a, Finding $b) => ($b->risk ?? 0.0) <=> ($a->risk ?? 0.0));

        return ['findings' => $findings, 'eol' => $report['eol'], 'truncated' => $report['truncated'] || $cut];
    }

    /**
     * @param  array{findings: list<Finding>, eol: ?array{name: string, version: string}, truncated: bool}  $report
     * @param  array{id: string, hex: string, repo_digest: ?string, platform: string, size: int}  $image
     */
    private function complete(Server $server, Model $resource, string $imageRef, ?ApplicationDeploymentQueue $deployment, ResolvedImageScanPolicy $policy, ImageSbom $sbom, array $report, array $image, CarbonImmutable $databaseBuiltAt, bool $isBaseline, ?string $baseImageRef = null): ImageScan
    {
        $findings = $report['findings'];

        $buildsImage = $this->buildsImage($resource, $imageRef);
        $classifierScan = $this->resourceScans($resource)->where('image_id', $image['id'])->first() ?? new ImageScan;
        $classifierScan->forceFill(['builds_image' => $buildsImage])->setRelation('resource', $resource);

        $summary = ScanSummary::build(
            $findings,
            ImageScanGate::classifierFor($classifierScan),
            IgnoreMatcher::forResource($resource),
            $policy->alertLevel,
            $report['eol'],
            $report['truncated'],
        );

        $scan = $this->upsert($resource, $image['id'], [
            'server_id' => $server->id,
            'image_sbom_id' => $sbom->id,
            'repo_digest' => $image['repo_digest'],
            'image_ref' => mb_substr($imageRef, 0, 512),
            ...($deployment !== null ? ['deployment_queue_id' => $deployment->id] : []),
            ...($baseImageRef !== null ? ['base_image_ref' => $baseImageRef] : []),
            'status' => ImageScanStatus::Completed,
            'error_code' => null,
            'error_context' => null,
            'summary' => $summary,
            'findings' => array_map(fn (Finding $finding) => $finding->toArray(), $findings),
            'builds_image' => $buildsImage,
            'is_baseline' => $isBaseline,
            'grype_db_built_at' => $databaseBuiltAt,
            'scanned_at' => now(),
        ]);
        $scan->setRelation('resource', $resource);

        Cache::forget(self::lastErrorCacheKey($resource));
        Cache::forget(self::progressCacheKey($resource));
        $this->broadcast($resource, (string) $scan->uuid);

        return $scan;
    }

    /**
     * Creates or updates the row of (resource, image ID). A row that another run inserts at the same time is updated.
     *
     * @param  array<string, mixed>  $values
     */
    private function upsert(Model $resource, string $imageId, array $values): ImageScan
    {
        $scan = $this->firstOrCreateScan($resource, $imageId, $values) ?? throw new ScanFailed(ImageScanErrorCode::Unknown);

        if (! $scan->wasRecentlyCreated) {
            $scan->forceFill($values)->save();
        }

        return $scan;
    }

    /**
     * `firstOrCreate` catches a unique violation and reads the other row. Its savepoint can roll back that row inside
     * an outer transaction, so one more attempt is made before giving up.
     *
     * @param  array<string, mixed>  $values
     */
    private function firstOrCreateScan(Model $resource, string $imageId, array $values): ?ImageScan
    {
        $keys = [
            'resource_type' => $resource->getMorphClass(),
            'resource_id' => $resource->getKey(),
            'image_id' => $imageId,
        ];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return ImageScan::query()->firstOrCreate($keys, $values);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        return null;
    }

    /**
     * Keeps an existing row of the image (and its results). Saves a Failed row only for an image without one.
     *
     * @param  array{id: string, hex: string, repo_digest: ?string, platform: string, size: int}  $image
     * @param  array<string, int|string>  $context
     */
    private function failAfterInspect(Server $server, Model $resource, string $imageRef, ?ApplicationDeploymentQueue $deployment, array $image, ImageScanErrorCode $code, array $context): ImageScan
    {
        $values = [
            'server_id' => $server->id,
            'repo_digest' => $image['repo_digest'],
            'image_ref' => mb_substr($imageRef, 0, 512),
            'deployment_queue_id' => $deployment?->id,
            'status' => ImageScanStatus::Failed,
            'error_code' => $code,
            'error_context' => $context,
            'builds_image' => $this->buildsImage($resource, $imageRef),
        ];

        $scan = $this->firstOrCreateScan($resource, $image['id'], $values);

        if ($scan === null || ! $scan->wasRecentlyCreated) {
            return $this->failBeforeRow($server, $resource, $imageRef, $image['id'], $code, $context, $image['repo_digest']);
        }

        $scan->setRelation('resource', $resource);
        $this->rememberError($resource, $code, $context);
        Cache::forget(self::progressCacheKey($resource));
        $this->broadcast($resource, (string) $scan->uuid);

        return $scan;
    }

    /**
     * @param  array<string, int|string>  $context
     */
    private function failBeforeRow(Server $server, Model $resource, string $imageRef, ?string $imageId, ImageScanErrorCode $code, array $context, ?string $repoDigest = null): ImageScan
    {
        $scan = $this->unsavedFailure($server, $resource, $imageRef, $imageId, $code, $context, $repoDigest);

        $this->rememberError($resource, $code, $context);
        Cache::forget(self::progressCacheKey($resource));
        $this->broadcast($resource, $this->runId);

        return $scan;
    }

    /**
     * @param  array<string, int|string>  $context
     */
    private function unsavedFailure(Server $server, Model $resource, string $imageRef, ?string $imageId, ImageScanErrorCode $code, array $context, ?string $repoDigest = null): ImageScan
    {
        $scan = (new ImageScan)->forceFill([
            'server_id' => $server->id,
            'resource_type' => $resource->getMorphClass(),
            'resource_id' => $resource->getKey(),
            'image_id' => $imageId,
            'repo_digest' => $repoDigest,
            'image_ref' => mb_substr($imageRef, 0, 512),
            'status' => ImageScanStatus::Failed,
            'error_code' => $code,
            'error_context' => $context,
        ]);
        $scan->setRelation('resource', $resource);

        return $scan;
    }

    /**
     * @param  array<string, int|string>  $context
     */
    private function rememberError(Model $resource, ImageScanErrorCode $code, array $context): void
    {
        Cache::put(self::lastErrorCacheKey($resource), [
            'code' => $code->value,
            'context' => $context,
            'at' => now()->toIso8601String(),
        ], now()->addDays(self::LAST_ERROR_TTL_DAYS));
    }

    /**
     * A finding is in a base layer when the layer of its package is one of the base image layers.
     *
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function withLayers(array $findings, ImageSbom $sbom): array
    {
        $layerByPackage = [];
        foreach ((array) $sbom->packages as $package) {
            if (is_array($package)) {
                $layerByPackage[(string) ($package['id'] ?? '')] = (string) ($package['layerId'] ?? '');
            }
        }
        $baseLayers = array_flip(array_filter(array_map('strval', (array) $sbom->base_layer_ids)));

        return array_map(function (Finding $finding) use ($layerByPackage, $baseLayers) {
            $layer = $layerByPackage[$finding->artifactId] ?? '';

            return $finding->withLayer($layer !== '' && isset($baseLayers[$layer]) ? 'base' : 'app');
        }, $findings);
    }

    /**
     * Docker images of the team are built by the team unless it says otherwise; Docker Hub official images are not.
     */
    private function buildsImage(Model $resource, string $imageRef): bool
    {
        if (! $resource instanceof Application) {
            return false;
        }
        if ($resource->build_pack !== 'dockerimage') {
            return true;
        }

        $buildsByTeam = $resource->settings?->image_builds_by_team;
        if ($buildsByTeam !== null) {
            return (bool) $buildsByTeam;
        }

        try {
            return ! ImageRef::parse($imageRef)->isDockerOfficial();
        } catch (InvalidArgumentException) {
            return true;
        }
    }

    /**
     * Runs one command batch and reads the exit code of $step. No output or no marker means the batch did not run.
     *
     * @param  list<string>  $commands
     * @return array{code: int, size: ?int, payload: string, exit_codes: array<string, int>}
     */
    private function runStep(Server $server, array $commands, string $step, int $timeout): array
    {
        $output = instant_remote_process($commands, $server, throwError: false, timeout: $timeout);
        $parsed = ScannerCommands::parseOutput($output);

        if ($output === null || trim($output) === '' || ! array_key_exists($step, $parsed['exit_codes'])) {
            throw new ScanFailed(ImageScanErrorCode::Unknown);
        }

        return [
            'code' => $parsed['exit_codes'][$step],
            'size' => $parsed['sizes'][$step] ?? null,
            'payload' => $parsed['payload'],
            'exit_codes' => $parsed['exit_codes'],
        ];
    }

    private function assertSucceeded(?int $code): void
    {
        if (in_array($code, self::EXIT_TIMEOUT, true)) {
            throw new ScanFailed(ImageScanErrorCode::Timeout);
        }
        if ($code !== 0) {
            throw new ScanFailed(ImageScanErrorCode::Unknown);
        }
    }

    /**
     * Output over the limit was cut by `head -c`: keep only the complete lines that were read.
     *
     * @param  array{code: int, size: ?int, payload: string, exit_codes: array<string, int>}  $result
     * @return array{0: string, 1: bool}
     */
    private function readPayload(array $result): array
    {
        $payload = $result['payload'];
        $cut = ($result['size'] ?? 0) > ScannerCommands::OUTPUT_LIMIT || strlen($payload) > ScannerCommands::OUTPUT_LIMIT;

        if ($cut) {
            $payload = substr($payload, 0, ScannerCommands::OUTPUT_LIMIT);
            $lastNewline = strrpos($payload, "\n");
            $payload = $lastNewline === false ? '' : substr($payload, 0, $lastNewline);
        }

        return [$payload, $cut];
    }

    private function broadcast(Model $resource, string $scanUuid): void
    {
        $teamId = method_exists($resource, 'team') ? $resource->team()?->id : null;

        ImageScanUpdated::dispatch($teamId, (string) $resource->getAttribute('uuid'), $scanUuid);
    }
}
