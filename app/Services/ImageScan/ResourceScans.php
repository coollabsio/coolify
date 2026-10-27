<?php

namespace App\Services\ImageScan;

use App\Actions\ImageScan\ScanImage;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ImageScanStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ImageScan;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Traits\HasImageScans;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use WeakMap;

/**
 * The current scan results of a resource page: what the Vulnerabilities page, the sidebar count, and the SBOM
 * download read. A service shows the results of its applications and databases.
 */
final class ResourceScans
{
    private const ADVISORY_ID_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/';

    public const RESOURCE_TYPES = ['application', 'service', 'database'];

    /** @var WeakMap<ImageScan, list<Finding>>|null */
    private static ?WeakMap $findings = null;

    /**
     * Finds a resource page's resource by type and uuid, only in the given teams.
     *
     * @param  list<int>  $teamIds
     */
    public static function find(string $type, string $uuid, array $teamIds): ?Model
    {
        $resource = match ($type) {
            'application' => Application::query()->where('uuid', $uuid)->first(),
            'service' => Service::query()->where('uuid', $uuid)->first(),
            'database' => collect(STANDALONE_DATABASE_MODELS)
                ->map(fn (string $class) => $class::query()->where('uuid', $uuid)->first())
                ->first(fn ($database) => $database !== null),
            default => null,
        };

        $teamId = $resource?->team()?->id;

        return $resource !== null && $teamId !== null && in_array($teamId, $teamIds, true) ? $resource : null;
    }

    /**
     * The resources that own scan rows: the applications and databases of a service, else the resource itself.
     *
     * @return Collection<int, Model>
     */
    public static function owners(Model $resource): Collection
    {
        if ($resource instanceof Service) {
            return $resource->applications()->get()->concat($resource->databases()->get())->values();
        }

        return collect([$resource]);
    }

    /**
     * The images Coolify scans for a resource without a deployment: each application and database of a service, or
     * the resource itself (a standalone database), with its image. Owners excluded from the status and owners without
     * an image are not scanned.
     *
     * @return list<array{0: Model, 1: string}>
     */
    public static function scanTargets(Model $resource): array
    {
        $owners = match (true) {
            $resource instanceof Service => $resource->applications()->get()->concat($resource->databases()->get()),
            in_array(HasImageScans::class, class_uses_recursive($resource), true) => collect([$resource]),
            default => collect(),
        };

        return $owners
            ->filter(fn (Model $owner): bool => self::isScanTarget($owner))
            ->map(fn (Model $owner): array => [$owner, trim((string) $owner->getAttribute('image'))])
            ->values()
            ->all();
    }

    /**
     * Whether Coolify scans the image of an application or database of a service, or of a standalone database.
     */
    public static function isScanTarget(Model $owner): bool
    {
        return ! $owner->getAttribute('exclude_from_status') && trim((string) $owner->getAttribute('image')) !== '';
    }

    /**
     * The current scans of every owner of the resource.
     *
     * @param  Collection<int, Model>|null  $owners
     * @param  list<string>  $columns
     * @return Collection<int, ImageScan>
     */
    public static function current(Model $resource, ?Collection $owners = null, array $columns = ['*']): Collection
    {
        return ($owners ?? self::owners($resource))
            ->flatMap(fn (Model $owner): Collection => self::currentFor($owner, $columns))
            ->values();
    }

    /**
     * The completed scan of the latest deployment that is not a preview, else the latest scan without a deployment.
     * The deployment decides, not the scan time: a redeploy of an older image links its older scan to the newer
     * deployment. The other images scanned by the same deployment (a compose application builds several) are current
     * too.
     *
     * @param  list<string>  $columns
     * @return Collection<int, ImageScan>
     */
    public static function currentFor(Model $owner, array $columns = ['*']): Collection
    {
        $query = fn (): Builder => ImageScan::query()
            ->select($columns)
            ->where('resource_type', $owner->getMorphClass())
            ->where('resource_id', $owner->getKey())
            ->where('status', ImageScanStatus::Completed)
            ->where(fn (Builder $query) => $query
                ->whereNull('deployment_queue_id')
                ->orWhereHas('deploymentQueue', fn (Builder $query) => $query->where('pull_request_id', 0)));

        $latest = self::latestFirst($query())->first();
        if (! $latest instanceof ImageScan) {
            return collect();
        }

        $scans = $latest->deployment_queue_id === null
            ? collect([$latest])
            : $query()->where('deployment_queue_id', $latest->deployment_queue_id)->orderByDesc('scanned_at')->orderByDesc('id')->get();

        return $scans->each(fn (ImageScan $scan) => $scan->setRelation('resource', $owner))->values();
    }

    /**
     * The images of a Compose application without a completed result in its current deployment: rows of that
     * deployment that failed or did not finish, and images of the stored Compose file without a row of it. Images set
     * with a variable are left out. 0 for other resources.
     *
     * @param  Collection<int, ImageScan>  $currentScans  The current scans of the application (see `currentFor()`).
     * @param  Collection<string, Collection<int, ImageScan>>|null  $deploymentRows  The rows of `composeDeploymentRows()`
     *                                                                               for lists, else one query here.
     */
    public static function uncheckedComposeImages(Model $owner, Collection $currentScans, ?Collection $deploymentRows = null): int
    {
        if (! self::isCompose($owner)) {
            return 0;
        }

        $deploymentId = $currentScans->first()?->deployment_queue_id;
        $rows = match (true) {
            $deploymentId === null => $currentScans,
            $deploymentRows?->has(self::key($owner)) === true => $deploymentRows->get(self::key($owner)),
            default => self::deploymentRowsQuery(collect([$deploymentId]))
                ->where('resource_id', $owner->getKey())
                ->get(),
        };

        $unfinished = $rows->filter(fn (ImageScan $row): bool => $row->status !== ImageScanStatus::Completed)->count();
        $rowNames = $rows->map(fn (ImageScan $row): string => ScanImage::imageName((string) $row->image_ref))->unique()->all();

        return $unfinished + count(array_diff(self::composeImageNames($owner), $rowNames));
    }

    /**
     * The scan rows of the current deployment of each Compose application among the owners, with one query, for
     * `uncheckedComposeImages()` in lists.
     *
     * @param  Collection<int, Model>  $owners
     * @param  Collection<string, Collection<int, ImageScan>>  $currentScans  Keyed by `key()` of the owner (see `currentForMany()`).
     * @return Collection<string, Collection<int, ImageScan>> Keyed by `key()` of the owner.
     */
    public static function composeDeploymentRows(Collection $owners, Collection $currentScans): Collection
    {
        $deployments = $owners
            ->filter(fn (Model $owner): bool => self::isCompose($owner))
            ->mapWithKeys(fn (Model $owner): array => [self::key($owner) => $currentScans->get(self::key($owner))?->first()?->deployment_queue_id])
            ->filter(fn ($deploymentId): bool => $deploymentId !== null);
        if ($deployments->isEmpty()) {
            return collect();
        }

        $rows = self::deploymentRowsQuery($deployments->values()->unique())->get();

        return $deployments->map(fn ($deploymentId, string $key): Collection => $rows
            ->filter(fn (ImageScan $row): bool => $row->resource_type.':'.$row->resource_id === $key && (int) $row->deployment_queue_id === (int) $deploymentId)
            ->values());
    }

    private static function isCompose(Model $owner): bool
    {
        return $owner instanceof Application && $owner->build_pack === 'dockercompose';
    }

    /**
     * @param  Collection<int, int|string>  $deploymentIds
     * @return Builder<ImageScan>
     */
    private static function deploymentRowsQuery(Collection $deploymentIds): Builder
    {
        return ImageScan::query()
            ->select(['id', 'resource_type', 'resource_id', 'deployment_queue_id', 'image_ref', 'status'])
            ->where('resource_type', (new Application)->getMorphClass())
            ->whereIn('deployment_queue_id', $deploymentIds->values()->all());
    }

    /**
     * The image names (without tag) of the services of the stored Compose file of an application.
     *
     * @return list<string>
     */
    private static function composeImageNames(Application $application): array
    {
        try {
            $compose = Yaml::parse((string) ($application->docker_compose ?: $application->docker_compose_raw));
        } catch (ParseException) {
            return [];
        }

        return collect((array) data_get($compose, 'services', []))
            ->map(fn ($service) => data_get($service, 'image'))
            ->filter(fn ($image): bool => is_string($image) && trim($image) !== '' && ! str_contains($image, '$'))
            ->map(fn (string $image): string => ScanImage::imageName($image))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Same as `owners()` for many resources: the applications and databases of all services with two queries.
     *
     * @param  Collection<int, Model>  $resources
     * @return Collection<string, Collection<int, Model>> Keyed by `key()` of the resource.
     */
    public static function ownersOfMany(Collection $resources): Collection
    {
        $services = $resources->filter(fn (Model $resource): bool => $resource instanceof Service)->keyBy('id');
        $unloaded = $services->reject(fn (Service $service): bool => $service->relationLoaded('applications') && $service->relationLoaded('databases'));

        if ($unloaded->isNotEmpty()) {
            $applications = ServiceApplication::query()->whereIn('service_id', $unloaded->keys())->get()->groupBy('service_id');
            $databases = ServiceDatabase::query()->whereIn('service_id', $unloaded->keys())->get()->groupBy('service_id');
            foreach ($unloaded as $id => $service) {
                $service->setRelation('applications', $applications->get($id, new EloquentCollection));
                $service->setRelation('databases', $databases->get($id, new EloquentCollection));
            }
        }

        return $resources->mapWithKeys(function (Model $resource): array {
            if (! $resource instanceof Service) {
                return [self::key($resource) => collect([$resource])];
            }

            $owners = $resource->applications->concat($resource->databases)->values();
            $owners->each(fn (Model $owner) => $owner->setRelation('service', $resource));

            return [self::key($resource) => $owners];
        });
    }

    /**
     * The current scans of many owners, like `currentFor()`, with two queries: the IDs of the current scans first,
     * then the columns of those scans only.
     *
     * @param  Collection<int, Model>  $owners
     * @param  list<string>  $columns
     * @return Collection<string, Collection<int, ImageScan>> Keyed by `key()` of the owner, with the owner loaded.
     */
    public static function currentForMany(Collection $owners, array $columns = ['*']): Collection
    {
        $byType = $owners->groupBy(fn (Model $owner): string => $owner->getMorphClass())
            ->map(fn (Collection $group): array => $group->map(fn (Model $owner) => $owner->getKey())->unique()->values()->all())
            ->all();
        $currentIds = self::currentScanIds($byType);
        if ($currentIds === []) {
            return collect();
        }

        $columns = $columns === ['*'] ? $columns : array_values(array_unique([...$columns, 'id', 'resource_type', 'resource_id', 'scanned_at']));
        $ownersByKey = $owners->keyBy(fn (Model $owner): string => self::key($owner));

        return ImageScan::query()
            ->select($columns)
            ->whereIn('id', $currentIds)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->get()
            ->each(fn (ImageScan $scan) => $scan->setRelation('resource', $ownersByKey->get($scan->resource_type.':'.$scan->resource_id)))
            ->groupBy(fn (ImageScan $scan): string => $scan->resource_type.':'.$scan->resource_id);
    }

    /**
     * The IDs of the current scans (see `currentFor()`) of the given owners, with one query that reads no JSON.
     *
     * @param  array<string, list<int|string>>  $idsByType  Owner IDs by morph type.
     * @return list<int>
     */
    public static function currentScanIds(array $idsByType): array
    {
        $idsByType = array_filter($idsByType);
        if ($idsByType === []) {
            return [];
        }

        return ImageScan::query()
            ->select(['id', 'resource_type', 'resource_id', 'deployment_queue_id', 'scanned_at'])
            ->where('status', ImageScanStatus::Completed)
            ->where(fn (Builder $query) => $query
                ->whereNull('deployment_queue_id')
                ->orWhereHas('deploymentQueue', fn (Builder $query) => $query->where('pull_request_id', 0)))
            ->where(function (Builder $query) use ($idsByType): void {
                foreach ($idsByType as $type => $ids) {
                    $query->orWhere(fn (Builder $query) => $query->where('resource_type', $type)->whereIn('resource_id', $ids));
                }
            })
            ->tap(fn (Builder $query) => self::latestFirst($query))
            ->get()
            ->groupBy(fn (ImageScan $scan): string => $scan->resource_type.':'.$scan->resource_id)
            ->flatMap(function (Collection $group): array {
                $latest = $group->first();

                return $latest->deployment_queue_id === null
                    ? [$latest->id]
                    : $group->where('deployment_queue_id', $latest->deployment_queue_id)->pluck('id')->all();
            })
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * The order of `currentFor()`: scans of finished deployments first (the latest first), then scans without a
     * deployment, then, only when nothing else exists, scans of deployments that did not finish (a blocked or failed
     * image never ran). Each by scan time after that.
     */
    private static function latestFirst(Builder $query): Builder
    {
        $deployments = (new ApplicationDeploymentQueue)->getTable();
        $scans = $query->getModel()->getTable();

        return $query->orderByRaw(
            "case when {$scans}.deployment_queue_id is null then 1 when (select {$deployments}.status from {$deployments} where {$deployments}.id = {$scans}.deployment_queue_id) = ? then 0 else 2 end",
            [ApplicationDeploymentStatus::FINISHED->value],
        )
            ->orderByDesc('deployment_queue_id')
            ->orderByDesc('scanned_at')
            ->orderByDesc('id');
    }

    /**
     * Loads what `ImageScanPolicyResolver::offState()` reads (server settings, app settings) for a list of
     * applications, services, and standalone databases, with a fixed number of queries per type.
     *
     * @param  Collection<int, Model>  $resources
     */
    public static function loadScanRelations(Collection $resources): void
    {
        $resources->groupBy(fn (Model $resource): string => $resource::class)
            ->each(fn (Collection $group, string $class) => EloquentCollection::make($group->all())->loadMissing(match ($class) {
                Application::class => ['settings', 'destination.server.settings'],
                Service::class => ['destination.server.settings', 'server.settings'],
                default => ['destination.server.settings'],
            }));
    }

    /**
     * A key for a resource or scan owner that is unique across resource types.
     */
    public static function key(Model $resource): string
    {
        return $resource->getMorphClass().':'.$resource->getKey();
    }

    /**
     * The relevant findings of a scan that an action fixes, from its stored security status.
     */
    public static function storedFixedCount(ImageScan $scan): int
    {
        return (int) data_get($scan->summary, 'security.facts.counts.relevant_fixed', 0);
    }

    /**
     * The summary of a scan before its security status is added (`SecurityStatus::store()`): the number of ignored
     * findings, the end-of-life OS, and whether the report was cut.
     *
     * @param  list<Finding>  $findings
     * @param  array{name: string, version: string}|null  $eol
     * @return array{ignored: int, eol_distro: array{name: string, version: string}|null, truncated: bool}
     */
    public static function summaryFor(array $findings, IgnoreMatcher $ignores, ?array $eol, bool $truncated): array
    {
        return [
            'ignored' => count(array_filter($findings, fn (Finding $finding): bool => $ignores->matches($finding) !== null)),
            'eol_distro' => $eol,
            'truncated' => $truncated,
        ];
    }

    /**
     * Writes the summaries again, for example after an ignore or a version check, so the stored security status
     * matches the findings.
     *
     * @param  Collection<int, ImageScan>  $scans  Loaded with their resource.
     */
    public static function refreshSummaries(Collection $scans): void
    {
        foreach ($scans as $scan) {
            $owner = $scan->resource;
            if (! $owner instanceof Model) {
                continue;
            }

            $summary = (array) $scan->summary;
            $scan->summary = self::summaryFor(
                self::findings($scan),
                IgnoreMatcher::forResource($owner),
                is_array($summary['eol_distro'] ?? null) ? $summary['eol_distro'] : null,
                (bool) ($summary['truncated'] ?? false),
            );
            SecurityStatus::store($scan);
            $scan->save();
        }
    }

    /**
     * The current scans of every resource of a team, for example to refresh the counts after a team-wide ignore.
     *
     * @return Collection<int, ImageScan>
     */
    public static function currentForTeam(int $teamId): Collection
    {
        return ImageScan::query()
            ->whereIn('server_id', Server::query()->where('team_id', $teamId)->select('id'))
            ->select(['resource_type', 'resource_id'])
            ->distinct()
            ->get()
            ->map(fn (ImageScan $row): ?Model => $row->resource)
            ->filter(fn (?Model $owner): bool => $owner !== null && method_exists($owner, 'team') && $owner->team()?->id === $teamId)
            ->flatMap(fn (Model $owner): Collection => self::currentFor($owner))
            ->values();
    }

    /**
     * The findings of a scan, decoded once per loaded scan.
     *
     * @return list<Finding>
     */
    public static function findings(ImageScan $scan): array
    {
        self::$findings ??= new WeakMap;

        return self::$findings[$scan] ??= array_map(
            fn ($row): Finding => Finding::fromArray((array) $row),
            array_values((array) $scan->findings),
        );
    }

    /**
     * Whether the resource now runs another image than the scanned one. Decided from the configuration and the
     * deployments, without a call to the server.
     */
    public static function runsOtherImage(ImageScan $scan, Model $owner): bool
    {
        if ($owner instanceof Application) {
            return match ($owner->build_pack) {
                'dockercompose' => false,
                'dockerimage' => ! self::sameImage(self::dockerImageRef($owner), (string) $scan->image_ref),
                default => self::builtCommitChanged($owner, $scan),
            };
        }

        return ! self::sameImage(trim((string) $owner->getAttribute('image')), (string) $scan->image_ref);
    }

    /**
     * The image an application runs now, named like the deploy job names it. Null when Coolify cannot tell, for
     * example before the first deploy.
     */
    public static function currentImageRef(Application $application): ?string
    {
        $ref = match (true) {
            $application->build_pack === 'dockercompose' => null,
            $application->build_pack === 'dockerimage' => self::dockerImageRef($application),
            filled($application->dockerfile) => ($application->docker_registry_image_name ?: $application->uuid).':latest',
            default => self::builtImageRef($application),
        };

        if ($ref === null) {
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
     * A link to the advisory, built by Coolify: NVD for CVE IDs, GitHub for GHSA IDs, else none.
     */
    public static function advisoryUrl(string $id): ?string
    {
        if (! self::isAdvisoryId($id)) {
            return null;
        }

        return match (true) {
            str_starts_with($id, 'CVE-') => 'https://nvd.nist.gov/vuln/detail/'.$id,
            str_starts_with($id, 'GHSA-') => 'https://github.com/advisories/'.$id,
            default => null,
        };
    }

    public static function isAdvisoryId(string $id): bool
    {
        return preg_match(self::ADVISORY_ID_PATTERN, $id) === 1;
    }

    /**
     * "low", "medium", or "high" from the EPSS score. Null without a score.
     */
    public static function exploitLikelihood(?float $epss): ?string
    {
        return match (true) {
            $epss === null => null,
            $epss < 0.1 => 'low',
            $epss < 0.5 => 'medium',
            default => 'high',
        };
    }

    private static function dockerImageRef(Application $application): ?string
    {
        $name = trim((string) $application->docker_registry_image_name);
        if ($name === '') {
            return null;
        }

        $tag = trim((string) $application->docker_registry_image_tag) ?: 'latest';

        return str_starts_with($tag, 'sha256-')
            ? $name.'@sha256:'.substr($tag, strlen('sha256-'))
            : $name.':'.$tag;
    }

    private static function builtImageRef(Application $application): ?string
    {
        $commit = self::latestDeployedCommit($application);

        return $commit === null ? null : ($application->docker_registry_image_name ?: $application->uuid).':'.mb_substr($commit, 0, 128);
    }

    private static function latestDeployedCommit(Application $application): ?string
    {
        $commit = ApplicationDeploymentQueue::query()
            ->where('application_id', (string) $application->getKey())
            ->where('pull_request_id', 0)
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->orderByDesc('id')
            ->value('commit');

        return self::usableCommit($commit);
    }

    private static function builtCommitChanged(Application $application, ImageScan $scan): bool
    {
        $deployed = self::latestDeployedCommit($application);
        $scanned = self::usableCommit($scan->deploymentQueue?->commit) ?? self::tagOf((string) $scan->image_ref);

        if ($deployed === null || $scanned === null || $scanned === 'latest') {
            return false;
        }

        return ! str_starts_with($deployed, $scanned) && ! str_starts_with($scanned, $deployed);
    }

    private static function usableCommit(mixed $commit): ?string
    {
        $commit = trim((string) $commit);

        return $commit === '' || $commit === 'HEAD' ? null : $commit;
    }

    private static function tagOf(string $ref): ?string
    {
        try {
            return ImageRef::parse($ref)->tag;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Same repository and tag. A scan of a pinned digest matches any tag of the repository, because the tag it
     * came from is not stored.
     */
    private static function sameImage(?string $configured, string $scanned): bool
    {
        $configured = trim((string) $configured);
        $scanned = trim($scanned);
        if ($configured === '' || $scanned === '') {
            return true;
        }

        try {
            $running = ImageRef::parse($configured);
            $result = ImageRef::parse($scanned);
        } catch (InvalidArgumentException) {
            return $configured === $scanned;
        }

        if ($running->qualifiedName() !== $result->qualifiedName()) {
            return false;
        }

        if ($result->digest !== null) {
            return $running->digest === null || $running->digest === $result->digest;
        }

        if ($running->digest !== null) {
            return false;
        }

        return ($running->tag ?? 'latest') === ($result->tag ?? 'latest');
    }
}
