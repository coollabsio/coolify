<?php

namespace App\Services\ImageScan;

use App\Enums\ImageScanFixState;
use App\Enums\ImageScanStatus;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Services\Access\AccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * The resources of a team and their current scan totals, for the team Vulnerabilities page and the dashboard
 * callout (spec 6.5). Only resources the user can see are loaded: the resource models hide the others for members
 * whose access is limited to some projects.
 */
final class TeamVulnerabilities
{
    public const STATE_LABELS = [
        'fixable_now' => 'Fixable now',
        'not_released' => 'Fix not released yet',
        'no_fix' => 'No fix',
        'ignored' => 'Ignored',
    ];

    private const STATE_RANK = ['fixable_now' => 0, 'not_released' => 1, 'no_fix' => 2, 'ignored' => 3];

    private const SEVERITY_RANK = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, 'unknown' => 0];

    /** At most this many scans have their findings decoded in one request. */
    public const MAX_DECODED_SCANS = 200;

    private bool $searchCapped = false;

    /**
     * @param  Collection<int, array{key: string, resource: Model, server: ?Server, project: ?Project, environment: ?Environment, link: ?string, scanned: bool, scans: Collection<int, ImageScan>, summary: array<string, mixed>}>  $entries
     */
    private function __construct(public readonly int $teamId, public readonly Collection $entries) {}

    public static function load(int $teamId): self
    {
        $resources = self::resources($teamId);
        ResourceScans::loadScanRelations($resources);
        $scanOn = $resources->filter(fn (Model $resource): bool => ImageScanPolicyResolver::isScanOn($resource))
            ->mapWithKeys(fn (Model $resource): array => [ResourceScans::key($resource) => true]);
        $owners = ResourceScans::ownersOfMany($resources->filter(fn (Model $resource): bool => $scanOn->has(ResourceScans::key($resource)))->values());
        $scans = ResourceScans::currentForMany($owners->flatten(1), VulnerabilityMarkers::SCAN_COLUMNS);

        $entries = $resources->map(function (Model $resource) use ($owners, $scans): array {
            $key = ResourceScans::key($resource);
            $resourceScans = $owners->get($key, collect())
                ->flatMap(fn (Model $owner): Collection => $scans->get(ResourceScans::key($owner), collect()))
                ->values();
            $scanned = $resourceScans->isNotEmpty();

            return [
                'key' => $key,
                'resource' => $resource,
                'server' => ImageScanPolicyResolver::mainServerOf($resource),
                'project' => $resource->environment?->project,
                'environment' => $resource->environment,
                'link' => self::vulnerabilitiesUrl($resource),
                'scanned' => $scanned,
                'scans' => $resourceScans,
                'summary' => VulnerabilityMarkers::summarize($resource, $resourceScans),
            ];
        })->values();

        return new self($teamId, $entries);
    }

    /**
     * How many resources the user can see have something fixable now at the alert level. Starts from the current
     * scans with a count above 0, so only those resources are loaded, not every resource of the team.
     */
    public static function fixableResourceCount(int $teamId): int
    {
        if (! instanceSettings()->is_image_scanning_allowed) {
            return 0;
        }

        $owners = ImageScan::query()
            // Whether a resource is scanned is decided below by the shared rule, not by the server of its scans.
            ->whereIn('server_id', Server::query()->where('team_id', $teamId)->select('id'))
            ->where('status', ImageScanStatus::Completed)
            ->select(['resource_type', 'resource_id'])
            ->distinct()
            ->toBase()
            ->get();
        $currentIds = ResourceScans::currentScanIds($owners->groupBy('resource_type')
            ->map(fn (Collection $rows): array => $rows->pluck('resource_id')->unique()->values()->all())
            ->all());
        if ($currentIds === []) {
            return 0;
        }

        $fixable = ImageScan::query()
            ->select(['id', 'resource_type', 'resource_id', 'summary'])
            ->whereIn('id', $currentIds)
            ->get()
            ->filter(fn (ImageScan $scan): bool => (int) data_get($scan->summary, 'fixable_now_at_level', 0) > 0)
            ->groupBy('resource_type')
            ->map(fn (Collection $scans): array => $scans->pluck('resource_id')->unique()->values()->all());

        $resourceIds = [];
        foreach ($fixable as $type => $ids) {
            $class = Relation::getMorphedModel($type) ?? $type;
            if (in_array($class, [ServiceApplication::class, ServiceDatabase::class], true)) {
                $resourceIds[Service::class] = [...($resourceIds[Service::class] ?? []), ...$class::query()->whereIn('id', $ids)->pluck('service_id')->all()];
            } elseif (in_array($class, AccessResolver::RESOURCE_MODELS, true)) {
                $resourceIds[$class] = [...($resourceIds[$class] ?? []), ...$ids];
            }
        }

        $resources = collect($resourceIds)->flatMap(fn (array $ids, string $class): Collection => $class::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->whereRelation('environment.project', 'team_id', $teamId)
            ->get());
        ResourceScans::loadScanRelations($resources);

        return $resources->filter(fn (Model $resource): bool => ImageScanPolicyResolver::isScanOn($resource))->count();
    }

    /**
     * Servers of the team that do not scan images. A transferred server is an archive and is not counted.
     */
    public static function serversNotScanning(int $teamId): int
    {
        return Server::query()
            ->where('team_id', $teamId)
            ->with('settings')
            ->get()
            ->filter(fn (Server $server): bool => ! $server->isTransferredAway() && $server->settings?->is_image_scan_enabled !== true)
            ->count();
    }

    /**
     * Resources of the entries that have a finding with this ID, alias, or package, with the state of the most
     * actionable match: Fixable now, Fix not released yet, No fix, then Ignored.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  list<string>  $severities  Only findings of these severities, or all when empty.
     * @param  int  $maxScans  At most this many matching scans are decoded; see `searchWasCapped()`.
     * @return Collection<int, array<string, mixed>> The entries with a `match` array.
     */
    public function search(Collection $entries, string $term, array $severities = [], int $maxScans = self::MAX_DECODED_SCANS): Collection
    {
        $this->searchCapped = false;
        $term = self::searchTerm($term);
        $scanIds = $entries->flatMap(fn (array $entry): Collection => $entry['scans']->pluck('id'))->all();
        if ($term === '' || $scanIds === []) {
            return collect();
        }

        // The database narrows the scans first, with "!" as the LIKE escape. jsonb text keeps "/" as is, while a
        // JSON string written by PHP has "\/", so "/" matches any run of characters.
        $pattern = '%'.strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_', '/' => '%']).'%';
        $findings = ImageScan::query()
            ->select(['id', 'findings'])
            ->whereIn('id', $scanIds)
            ->whereRaw("LOWER(CAST(findings AS TEXT)) LIKE ? ESCAPE '!'", [$pattern])
            ->orderByDesc('id')
            ->limit($maxScans + 1)
            ->get();
        if ($findings->count() > $maxScans) {
            $this->searchCapped = true;
            $findings = $findings->take($maxScans);
        }
        $findings = $findings->keyBy('id');
        $ignores = $this->ignores();

        return $entries
            ->map(function (array $entry) use ($term, $severities, $findings, $ignores): ?array {
                $matches = [];
                foreach ($entry['scans'] as $scan) {
                    $loaded = $findings->get($scan->id);
                    if (! $loaded instanceof ImageScan) {
                        continue;
                    }

                    $scan->setAttribute('findings', $loaded->findings);
                    $classify = ImageScanGate::classifierFor($scan);
                    $matcher = $this->matcherFor($scan->resource, $ignores);
                    foreach (ResourceScans::findings($scan) as $finding) {
                        if (! self::matches($finding, $term) || ($severities !== [] && ! in_array(self::severityOf($finding), $severities, true))) {
                            continue;
                        }

                        $matches[] = [
                            'id' => $finding->id,
                            'package' => $finding->package,
                            'version' => $finding->version,
                            'fixedIn' => $finding->fixedIn,
                            'severity' => self::severityOf($finding),
                            'state' => $matcher->matches($finding) !== null ? 'ignored' : $classify($finding)->value,
                        ];
                    }
                }

                if ($matches === []) {
                    return null;
                }

                usort($matches, fn (array $a, array $b): int => [self::STATE_RANK[$a['state']], self::SEVERITY_RANK[$b['severity']], $a['id']]
                    <=> [self::STATE_RANK[$b['state']], self::SEVERITY_RANK[$a['severity']], $b['id']]);

                return [...$entry, 'match' => [...$matches[0], 'more' => count($matches) - 1]];
            })
            ->filter()
            ->sortBy([
                fn (array $a, array $b): int => self::STATE_RANK[$a['match']['state']] <=> self::STATE_RANK[$b['match']['state']],
                fn (array $a, array $b): int => self::SEVERITY_RANK[$b['match']['severity']] <=> self::SEVERITY_RANK[$a['match']['severity']],
                fn (array $a, array $b): int => strnatcasecmp((string) $a['resource']->name, (string) $b['resource']->name),
            ])
            ->values();
    }

    /**
     * Distinct packages with a fix the user can apply now, per entry key, for the "Fix" column when there is no
     * version to update to.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array<string, int>
     */
    public function fixablePackageCounts(Collection $entries): array
    {
        $scanIds = $entries->flatMap(fn (array $entry): Collection => $entry['scans']->pluck('id'))->take(self::MAX_DECODED_SCANS);
        if ($scanIds->isEmpty()) {
            return [];
        }

        $findings = ImageScan::query()->select(['id', 'findings'])->whereIn('id', $scanIds)->get()->keyBy('id');
        $ignores = $this->ignores();

        $counts = [];
        foreach ($entries as $entry) {
            $packages = [];
            foreach ($entry['scans'] as $scan) {
                $loaded = $findings->get($scan->id);
                if (! $loaded instanceof ImageScan) {
                    continue;
                }

                $scan->setAttribute('findings', $loaded->findings);
                $classify = ImageScanGate::classifierFor($scan);
                $matcher = $this->matcherFor($scan->resource, $ignores);
                foreach (ResourceScans::findings($scan) as $finding) {
                    if ($matcher->matches($finding) === null && $classify($finding) === ImageScanFixState::FixableNow) {
                        $packages[$scan->image_ref."\0".$finding->package] = true;
                    }
                }
            }
            $counts[$entry['key']] = count($packages);
        }

        return $counts;
    }

    /**
     * Whether the last search found more matching scans than it reads, so some resources may be missing.
     */
    public function searchWasCapped(): bool
    {
        return $this->searchCapped;
    }

    /**
     * The search term: lower case, at most 100 characters, without quotes and backslashes (they never occur in IDs
     * or package names and would need JSON escaping).
     */
    public static function searchTerm(string $term): string
    {
        return mb_substr(mb_strtolower(trim(str_replace(['"', '\\'], '', $term))), 0, 100);
    }

    public static function severityOf(Finding $finding): string
    {
        $severity = strtolower($finding->severity);

        return isset(self::SEVERITY_RANK[$severity]) ? $severity : 'unknown';
    }

    private static function matches(Finding $finding, string $term): bool
    {
        foreach ([$finding->id, ...$finding->aliases, $finding->package] as $value) {
            if (str_contains(mb_strtolower($value), $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, ImageScanIgnore>
     */
    private function ignores(): Collection
    {
        return ImageScanIgnore::query()->active()->where('team_id', $this->teamId)->get();
    }

    /**
     * @param  Collection<int, ImageScanIgnore>  $ignores
     */
    private function matcherFor(?Model $owner, Collection $ignores): IgnoreMatcher
    {
        return IgnoreMatcher::fromIgnores($ignores->filter(fn (ImageScanIgnore $ignore): bool => $ignore->resource_type === null
            || ($owner !== null && $ignore->resource_type === $owner->getMorphClass() && (int) $ignore->resource_id === (int) $owner->getKey())));
    }

    /**
     * Applications, services, and standalone databases of the team, with their environment, project, and server.
     *
     * @return Collection<int, Model>
     */
    private static function resources(int $teamId): Collection
    {
        $inTeam = fn (Builder $query): Builder => $query->whereRelation('environment.project', 'team_id', $teamId);

        return collect()
            ->concat($inTeam(Application::query())->with(['environment.project', 'settings', 'destination.server.settings'])->get())
            ->concat($inTeam(Service::query())->with(['environment.project', 'destination.server.settings', 'server.settings'])->get())
            ->concat(collect(STANDALONE_DATABASE_MODELS)->flatMap(fn (string $class) => $inTeam($class::query())
                ->with(['environment.project', 'destination.server.settings'])
                ->get()))
            ->values();
    }

    /**
     * The Vulnerabilities page of the resource.
     */
    public static function vulnerabilitiesUrl(Model $resource): ?string
    {
        $environment = $resource->environment;
        $project = $environment?->project;
        if (! $environment instanceof Environment || ! $project instanceof Project) {
            return null;
        }

        $parameters = ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid];

        return match (true) {
            $resource instanceof Application => route('project.application.vulnerabilities', [...$parameters, 'application_uuid' => $resource->uuid]),
            $resource instanceof Service => route('project.service.vulnerabilities', [...$parameters, 'service_uuid' => $resource->uuid]),
            default => route('project.database.vulnerabilities', [...$parameters, 'database_uuid' => $resource->uuid]),
        };
    }
}
