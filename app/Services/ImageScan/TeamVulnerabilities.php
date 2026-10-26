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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

    /** Search terms with only these characters are looked up in the stored findings by the database first. */
    private const PREFILTER_PATTERN = '/\A[a-z0-9._:-]+\z/';

    /**
     * @param  Collection<int, array{key: string, resource: Model, server: ?Server, project: ?Project, environment: ?Environment, link: ?string, scanned: bool, scans: Collection<int, ImageScan>, summary: array<string, mixed>}>  $entries
     */
    private function __construct(public readonly int $teamId, public readonly Collection $entries) {}

    public static function load(int $teamId): self
    {
        $resources = self::resources($teamId);
        $owners = ResourceScans::ownersOfMany($resources);
        $scans = ResourceScans::currentForMany($owners->flatten(1), VulnerabilityMarkers::SCAN_COLUMNS);

        $entries = $resources->map(function (Model $resource) use ($owners, $scans): array {
            $key = ResourceScans::key($resource);
            $resourceScans = $owners->get($key, collect())
                ->flatMap(fn (Model $owner): Collection => $scans->get(ResourceScans::key($owner), collect()))
                ->values();
            $server = self::serverOf($resource);
            $scanned = $server?->settings?->is_image_scan_enabled === true && $resourceScans->isNotEmpty();

            return [
                'key' => $key,
                'resource' => $resource,
                'server' => $server,
                'project' => $resource->environment?->project,
                'environment' => $resource->environment,
                'link' => self::vulnerabilitiesUrl($resource),
                'scanned' => $scanned,
                'scans' => $scanned ? $resourceScans : collect(),
                'summary' => VulnerabilityMarkers::summarize($resource, $scanned ? $resourceScans : collect()),
            ];
        })->values();

        return new self($teamId, $entries);
    }

    /**
     * How many resources the user can see have something fixable now at the alert level. Teams without any scan
     * skip loading the resources.
     */
    public static function fixableResourceCount(int $teamId): int
    {
        if (! instanceSettings()->is_image_scanning_allowed) {
            return 0;
        }

        $hasScans = ImageScan::query()
            ->whereIn('server_id', Server::query()->where('team_id', $teamId)->select('id'))
            ->where('status', ImageScanStatus::Completed)
            ->exists();

        return $hasScans
            ? self::load($teamId)->entries->filter(fn (array $entry): bool => $entry['summary']['count'] > 0)->count()
            : 0;
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
     * @return Collection<int, array<string, mixed>> The entries with a `match` array.
     */
    public function search(Collection $entries, string $term, array $severities = []): Collection
    {
        $term = mb_strtolower(trim($term));
        $scanIds = $entries->flatMap(fn (array $entry): Collection => $entry['scans']->pluck('id'))->all();
        if ($term === '' || $scanIds === []) {
            return collect();
        }

        $query = ImageScan::query()->select(['id', 'findings'])->whereIn('id', $scanIds);
        if (preg_match(self::PREFILTER_PATTERN, $term) === 1) {
            $query->whereRaw('LOWER(CAST(findings AS TEXT)) LIKE ?', ['%'.$term.'%']);
        }
        $findings = $query->get()->keyBy('id');
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
     * Distinct packages with a fix the user can apply now, per entry key, for the "Fix" column of built images.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array<string, int>
     */
    public function fixablePackageCounts(Collection $entries): array
    {
        $scans = $entries->flatMap(fn (array $entry): Collection => $entry['scans']->filter(fn (ImageScan $scan): bool => (bool) $scan->builds_image));
        if ($scans->isEmpty()) {
            return [];
        }

        $findings = ImageScan::query()->select(['id', 'findings'])->whereIn('id', $scans->pluck('id'))->get()->keyBy('id');
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
            ->concat($inTeam(Application::query())->with(['environment.project', 'destination.server.settings'])->get())
            ->concat($inTeam(Service::query())->with(['environment.project', 'server.settings'])->get())
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

    private static function serverOf(Model $resource): ?Server
    {
        $server = $resource instanceof Service
            ? $resource->server
            : $resource->destination?->server;

        return $server instanceof Server ? $server : null;
    }
}
