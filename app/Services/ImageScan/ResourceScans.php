<?php

namespace App\Services\ImageScan;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ImageScanStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ImageScan;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
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
     * The latest completed scan that is not a preview. The other images scanned by the same deployment (a compose
     * application builds several) are current too.
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
                ->orWhereHas('deploymentQueue', fn (Builder $query) => $query->where('pull_request_id', 0)))
            ->orderByDesc('scanned_at')
            ->orderByDesc('id');

        $latest = $query()->first();
        if (! $latest instanceof ImageScan) {
            return collect();
        }

        $scans = $latest->deployment_queue_id === null
            ? collect([$latest])
            : $query()->where('deployment_queue_id', $latest->deployment_queue_id)->get();

        return $scans->each(fn (ImageScan $scan) => $scan->setRelation('resource', $owner))->values();
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
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
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
     * Fixable now at the alert level, from the stored summaries: the sidebar count. 0 while the resource is not scanned.
     */
    public static function fixableNowCount(Model $resource): int
    {
        if (! ImageScanPolicyResolver::isScanOn($resource)) {
            return 0;
        }

        return (int) self::current($resource, null, ['id', 'resource_type', 'resource_id', 'deployment_queue_id', 'summary', 'scanned_at'])
            ->sum(fn (ImageScan $scan): int => (int) data_get($scan->summary, 'fixable_now_at_level', 0));
    }

    /**
     * Writes the summaries again, for example after an ignore, so the counts match the findings.
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
            $scan->summary = ScanSummary::build(
                self::findings($scan),
                ImageScanGate::classifierFor($scan),
                IgnoreMatcher::forResource($owner),
                ImageScanPolicyResolver::for($owner)->alertLevel,
                is_array($summary['eol_distro'] ?? null) ? $summary['eol_distro'] : null,
                (bool) ($summary['truncated'] ?? false),
            );
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
