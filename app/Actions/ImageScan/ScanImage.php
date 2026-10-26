<?php

namespace App\Actions\ImageScan;

use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanStatus;
use App\Events\ImageScanUpdated;
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
use App\Services\ImageScan\ScanSummary;
use App\Services\ImageScan\SyftPackageParser;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Scans one local image on a server with Syft and Grype and stores the result for a resource (spec 4.1).
 * Scan problems never throw: the returned scan has status Failed and an error code.
 */
class ScanImage
{
    use AsAction;

    private const LOCK_TTL = 1800;

    /** ScannerCommands budgets plus 60 s. */
    private const TIMEOUT_DEFAULT = 120;

    private const TIMEOUT_DB_UPDATE = 960;

    private const TIMEOUT_SBOM = 1260;

    private const TIMEOUT_GRYPE = 360;

    private const DB_MAX_AGE_DAYS = 5;

    private const DISK_RESERVE_BYTES = 1073741824;

    /** Exit code of `timeout`. */
    private const EXIT_TIMEOUT = 124;

    /** Exit code of a failed `docker run`, for example a missing bind mount source. */
    private const EXIT_DOCKER_RUN = 125;

    /** Image ID of a scan row before `docker image inspect` ran. */
    private const PENDING_IMAGE_ID = '';

    public function handle(Server $server, Model $resource, string $imageRef, ?ApplicationDeploymentQueue $deployment = null, int $lockWaitSeconds = 0): ImageScan
    {
        $policy = ImageScanPolicyResolver::for($resource, $server);
        $context = ['server' => (string) $server->name];

        if ($server->isTransferredAway() || ! $policy->scanEnabled) {
            return (new ImageScan)->forceFill([
                'server_id' => $server->id,
                'resource_type' => $resource->getMorphClass(),
                'resource_id' => $resource->getKey(),
                'image_id' => self::PENDING_IMAGE_ID,
                'image_ref' => mb_substr($imageRef, 0, 512),
                'status' => ImageScanStatus::Failed,
                'error_code' => ImageScanErrorCode::ScanningOff,
                'error_context' => $context,
            ]);
        }

        $scan = $this->pendingScan($server, $resource, $imageRef, $deployment);

        try {
            ImageRef::parse($imageRef);
        } catch (InvalidArgumentException) {
            return $this->fail($scan, $resource, ImageScanErrorCode::Unknown, $context);
        }

        $lock = Cache::lock("image-scan:{$server->id}", self::LOCK_TTL, Str::random(40));
        if (! $this->acquire($lock, $lockWaitSeconds)) {
            return $this->fail($scan, $resource, ImageScanErrorCode::LockBusy, $context);
        }

        try {
            return $this->scan($scan, $server, $resource, $imageRef, $deployment, $policy, $context);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{server: string}  $context
     */
    private function scan(ImageScan $scan, Server $server, Model $resource, string $imageRef, ?ApplicationDeploymentQueue $deployment, ResolvedImageScanPolicy $policy, array $context): ImageScan
    {
        $commands = new ScannerCommands($server, Str::lower(Str::random(24)));
        $isBaseline = ! $this->resourceScans($resource)->where('status', ImageScanStatus::Completed)->exists();

        $scan->forceFill([
            'status' => ImageScanStatus::Running,
            'error_code' => null,
            'error_context' => null,
            'builds_image' => $this->buildsImage($resource, $imageRef),
        ])->save();
        $this->broadcast($scan, $resource);

        try {
            $databaseBuiltAt = $this->databaseBuiltAt($commands);
            $image = $this->inspect($commands, $imageRef);
            $scan = $this->claimRow($scan, $resource, $image['id'], $deployment);
            $this->checkDiskSpace($commands, $image['size']);
            [$sbom, $grype] = $this->sbomAndReport($commands, $imageRef, $image);

            return $this->complete($scan, $resource, $policy, $sbom, $grype, $image, $databaseBuiltAt, $isBaseline);
        } catch (ScanFailed $e) {
            return $this->fail($scan, $resource, $e->errorCode, [...$context, ...$e->context]);
        } catch (Throwable $e) {
            $this->fail($scan, $resource, ImageScanErrorCode::Unknown, $context);

            throw $e;
        } finally {
            instant_remote_process($commands->cleanup(), $server, throwError: false, timeout: self::TIMEOUT_DEFAULT);
        }
    }

    /**
     * The row that shows the scan until the image ID is known: the row of the resource without an image ID.
     */
    private function pendingScan(Server $server, Model $resource, string $imageRef, ?ApplicationDeploymentQueue $deployment): ImageScan
    {
        $scan = $this->resourceScans($resource)->where('image_id', self::PENDING_IMAGE_ID)->first()
            ?? (new ImageScan)->forceFill([
                'resource_type' => $resource->getMorphClass(),
                'resource_id' => $resource->getKey(),
                'image_id' => self::PENDING_IMAGE_ID,
            ]);

        $scan->forceFill(['server_id' => $server->id, 'image_ref' => mb_substr($imageRef, 0, 512)]);
        if ($deployment !== null) {
            $scan->deployment_queue_id = $deployment->id;
        }

        return $scan;
    }

    /**
     * Moves the running scan to the row of (resource, image ID); an earlier row of that image is reused.
     */
    private function claimRow(ImageScan $scan, Model $resource, string $imageId, ?ApplicationDeploymentQueue $deployment): ImageScan
    {
        $existing = $this->resourceScans($resource)->where('image_id', $imageId)->whereKeyNot($scan->getKey())->first();

        if ($existing === null) {
            $scan->forceFill(['image_id' => $imageId])->save();

            return $scan;
        }

        $existing->forceFill([
            ...$scan->only(['server_id', 'image_ref', 'builds_image']),
            ...($deployment !== null ? ['deployment_queue_id' => $deployment->id] : []),
            'status' => ImageScanStatus::Running,
            'error_code' => null,
            'error_context' => null,
        ])->save();
        $scan->delete();
        $this->broadcast($existing, $resource);

        return $existing;
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

    /**
     * Updates a missing database once. Grype refuses a database older than 5 days.
     */
    private function databaseBuiltAt(ScannerCommands $commands): CarbonImmutable
    {
        $builtAt = $this->databaseStatus($commands);

        if ($builtAt === null) {
            instant_remote_process($commands->dbUpdate(InstanceSettings::get()->image_scan_db_url), $commands->server, throwError: false, timeout: self::TIMEOUT_DB_UPDATE);
            $builtAt = $this->databaseStatus($commands) ?? throw new ScanFailed(ImageScanErrorCode::DbMissing);
        }

        if ($builtAt->lt(now()->subDays(self::DB_MAX_AGE_DAYS))) {
            throw new ScanFailed(ImageScanErrorCode::DbTooOld, ['n' => (int) floor($builtAt->diffInDays(now()))]);
        }

        return $builtAt;
    }

    private function databaseStatus(ScannerCommands $commands): ?CarbonImmutable
    {
        $result = $this->runStep($commands->server, $commands->dbStatus(), 'db-status', self::TIMEOUT_DEFAULT);
        if ($result['code'] === self::EXIT_TIMEOUT) {
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
     */
    private function checkDiskSpace(ScannerCommands $commands, int $imageSize): void
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
     * Reuses the SBOM of the image on this server. When its file is gone, Grype cannot mount it: the row is
     * deleted and the SBOM is created once more.
     *
     * @param  array{id: string, hex: string, repo_digest: ?string, platform: string, size: int}  $image
     * @return array{0: ImageSbom, 1: array{code: int, size: ?int, payload: string, exit_codes: array<string, int>}}
     */
    private function sbomAndReport(ScannerCommands $commands, string $imageRef, array $image): array
    {
        $sbom = ImageSbom::query()->where('server_id', $commands->server->id)->where('image_id', $image['id'])->first();

        if ($sbom !== null) {
            $grype = $this->runStep($commands->server, $commands->grype($image['hex']), 'grype', self::TIMEOUT_GRYPE);

            if ($grype['code'] !== self::EXIT_DOCKER_RUN) {
                $this->assertSucceeded($grype['code']);
                $sbom->forceFill(['last_used_at' => now()])->save();

                return [$sbom, $grype];
            }

            $sbom->delete();
        }

        $sbom = $this->createSbom($commands, $imageRef, $image);
        $grype = $this->runStep($commands->server, $commands->grype($image['hex']), 'grype', self::TIMEOUT_GRYPE);
        $this->assertSucceeded($grype['code']);

        return [$sbom, $grype];
    }

    /**
     * @param  array{id: string, hex: string, repo_digest: ?string, platform: string, size: int}  $image
     */
    private function createSbom(ScannerCommands $commands, string $imageRef, array $image): ImageSbom
    {
        $result = $this->runStep($commands->server, $commands->sbomFromLocal($imageRef, $image['hex']), 'save', self::TIMEOUT_SBOM);
        $this->assertSucceeded($result['code']);
        $this->assertSucceeded($result['exit_codes']['sbom'] ?? null);

        $packages = $this->runStep($commands->server, $commands->packages($image['hex']), 'packages', self::TIMEOUT_GRYPE);
        $this->assertSucceeded($packages['code']);
        [$payload, $cut] = $this->readPayload($packages);
        $parsed = (new SyftPackageParser)->parse($payload);

        return ImageSbom::query()->create([
            'server_id' => $commands->server->id,
            'image_id' => $image['id'],
            'platform' => $image['platform'],
            'packages' => array_map(fn (Package $package) => $package->toArray(), $parsed['packages']),
            'base_layer_ids' => [],
            'packages_truncated' => $parsed['truncated'] || $cut,
            'last_used_at' => now(),
        ]);
    }

    /**
     * @param  array{code: int, size: ?int, payload: string, exit_codes: array<string, int>}  $grype
     * @param  array{id: string, hex: string, repo_digest: ?string, platform: string, size: int}  $image
     */
    private function complete(ImageScan $scan, Model $resource, ResolvedImageScanPolicy $policy, ImageSbom $sbom, array $grype, array $image, CarbonImmutable $databaseBuiltAt, bool $isBaseline): ImageScan
    {
        [$payload, $cut] = $this->readPayload($grype);
        $report = (new GrypeReportParser)->parse($payload);
        $findings = $this->withLayers($report['findings'], $sbom);
        usort($findings, fn (Finding $a, Finding $b) => ($b->risk ?? 0.0) <=> ($a->risk ?? 0.0));

        $scan->forceFill([
            'image_sbom_id' => $sbom->id,
            'repo_digest' => $image['repo_digest'],
            'grype_db_built_at' => $databaseBuiltAt,
            'scanned_at' => now(),
            'is_baseline' => $isBaseline,
        ]);
        $scan->setRelation('resource', $resource);

        $summary = ScanSummary::build(
            $findings,
            ImageScanGate::classifierFor($scan),
            IgnoreMatcher::forResource($resource),
            $policy->alertLevel,
            $report['eol'],
            $report['truncated'] || $cut,
        );

        $scan->forceFill([
            'status' => ImageScanStatus::Completed,
            'error_code' => null,
            'error_context' => null,
            'summary' => $summary,
            'findings' => array_map(fn (Finding $finding) => $finding->toArray(), $findings),
        ])->save();
        $this->broadcast($scan, $resource);

        return $scan;
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
        if ($code === self::EXIT_TIMEOUT) {
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

    /**
     * @param  array<string, int|string>  $context
     */
    private function fail(ImageScan $scan, Model $resource, ImageScanErrorCode $code, array $context): ImageScan
    {
        $scan->forceFill([
            'status' => ImageScanStatus::Failed,
            'error_code' => $code,
            'error_context' => $context,
        ])->save();
        $this->broadcast($scan, $resource);

        return $scan;
    }

    private function broadcast(ImageScan $scan, Model $resource): void
    {
        $teamId = method_exists($resource, 'team') ? $resource->team()?->id : null;

        ImageScanUpdated::dispatch($teamId, (string) $resource->getAttribute('uuid'), (string) $scan->uuid);
    }
}
