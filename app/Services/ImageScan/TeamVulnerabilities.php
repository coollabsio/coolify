<?php

namespace App\Services\ImageScan;

use App\Enums\ImageScanStatus;
use App\Enums\SecurityStatusType;
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
use Illuminate\Support\Facades\Schema;

/**
 * The resources of a team and their stored security status, for the team Security page and the dashboard callout
 * (spec 15.8). Only resources the user can see are loaded: the resource models hide the others for members whose
 * access is limited to some projects. Lists read only stored statuses; only the search decodes findings.
 */
final class TeamVulnerabilities
{
    /** The sort order of the team page. */
    public const STATUS_ORDER = ['update_now', 'update_available', 'no_fix', 'check_needed', 'ok', 'not_checked'];

    /** The fix state of a search match, in the words of the resource page. */
    public const STATE_LABELS = [
        'fixed' => 'Fix available upstream',
        'no_fix' => 'No fix yet',
        'ignored' => 'Ignored',
    ];

    private const STATE_RANK = ['fixed' => 0, 'no_fix' => 1, 'ignored' => 2];

    private const SEVERITY_RANK = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, 'unknown' => 0];

    /**
     * Large text columns the list never reads. The Compose file of a Compose app is read (its images), so those two
     * columns are loaded only for Compose apps.
     */
    private const UNREAD_COLUMNS = [
        'applications' => ['docker_compose_raw', 'docker_compose', 'docker_compose_domains', 'dockerfile', 'custom_labels', 'custom_nginx_configuration'],
        'services' => ['docker_compose_raw', 'docker_compose'],
    ];

    /** At most this many scans have their findings decoded in one request. */
    public const MAX_DECODED_SCANS = 200;

    private bool $searchCapped = false;

    /**
     * @param  Collection<int, array{key: string, resource: Model, server: ?Server, project: ?Project, environment: ?Environment, link: ?string, scanned: bool, scans: Collection<int, ImageScan>, security: ResourceSecurity, images: array{update: int, total: int}}>  $entries
     */
    private function __construct(public readonly int $teamId, public readonly Collection $entries) {}

    public static function load(int $teamId): self
    {
        $resources = self::resources($teamId);
        ResourceScans::loadScanRelations($resources);
        $owners = ResourceScans::ownersOfMany($resources->filter(fn (Model $resource): bool => ImageScanPolicyResolver::isScanOn($resource))->values());
        $scans = ResourceScans::currentForMany($owners->flatten(1), VulnerabilityMarkers::SCAN_COLUMNS);
        $statuses = SecurityStatus::storedMany($resources, $scans);

        $entries = $resources->map(function (Model $resource) use ($owners, $scans, $statuses): array {
            $key = ResourceScans::key($resource);
            $resourceScans = $owners->get($key, collect())
                ->flatMap(fn (Model $owner): Collection => $scans->get(ResourceScans::key($owner), collect()))
                ->values();

            return [
                'key' => $key,
                'resource' => $resource,
                'server' => ImageScanPolicyResolver::mainServerOf($resource),
                'project' => $resource->environment?->project,
                'environment' => $resource->environment,
                'link' => self::vulnerabilitiesUrl($resource),
                'scanned' => $resourceScans->isNotEmpty(),
                'scans' => $resourceScans,
                'security' => $statuses[$key],
                'images' => self::imageCounts($resource, $owners->get($key, collect()), $scans, $statuses[$key]),
            ];
        })->values();

        return new self($teamId, $entries);
    }

    /**
     * The images of a resource that the status counts, and how many of them need an update (stored status
     * update_now or update_available). A scanned owner of a service without a current scan is one image.
     *
     * @param  Collection<int, Model>  $owners
     * @param  Collection<string, Collection<int, ImageScan>>  $scans  Keyed by `ResourceScans::key()` of the owner.
     * @return array{update: int, total: int}
     */
    private static function imageCounts(Model $resource, Collection $owners, Collection $scans, ResourceSecurity $security): array
    {
        $update = 0;
        $total = 0;
        foreach ($owners as $owner) {
            if ($resource instanceof Service && ! ResourceScans::isScanTarget($owner)) {
                continue;
            }
            $ownerScans = $scans->get(ResourceScans::key($owner), collect());
            $total += max(1, $ownerScans->count());
            $update += $ownerScans->filter(fn (ImageScan $scan): bool => in_array(data_get($scan->summary, 'security.status'), ['update_now', 'update_available'], true))->count();
        }

        return ['update' => $update, 'total' => max($total, (int) ($security->checkContext['total'] ?? 0))];
    }

    /**
     * "2 of 3 images" under the status of a resource with several images that needs an update. Null otherwise.
     *
     * @param  array{update: int, total: int}  $images
     */
    public static function imagesLine(ResourceSecurity $security, array $images): ?string
    {
        if (! in_array($security->status, [SecurityStatusType::UpdateNow, SecurityStatusType::UpdateAvailable], true)
            || $images['total'] < 2 || $images['update'] < 1) {
            return null;
        }

        return "{$images['update']} of {$images['total']} images";
    }

    /**
     * The status text of the team page: the badge, "No action needed" for ok, "Not checked" for not_checked.
     */
    public static function statusLabel(SecurityStatusType $status): string
    {
        return match ($status) {
            SecurityStatusType::Ok => 'No action needed',
            SecurityStatusType::NotChecked => 'Not checked',
            default => (string) SecuritySentences::badge($status),
        };
    }

    /** The Action column of a resource that has a problem with no fix yet (no_fix). */
    public const NO_FIX_ACTION = 'No fix released yet. Coolify checks daily.';

    /**
     * The Action column: the sentence of the primary action (update_now, update_available), "Check again: {reason}."
     * (check_needed, muted), that no fix exists yet (no_fix, muted, no link), or why the resource is not checked
     * (not_checked, muted, no link). Null for ok.
     *
     * @return array{text: string, link: bool, muted: bool}|null
     */
    public static function action(ResourceSecurity $security): ?array
    {
        return match ($security->status) {
            SecurityStatusType::NoFix => ['text' => SecuritySentences::noFixAction($security) ?? self::NO_FIX_ACTION, 'link' => false, 'muted' => true],
            SecurityStatusType::UpdateNow, SecurityStatusType::UpdateAvailable => filled($security->primary()?->sentence)
                ? ['text' => (string) $security->primary()->sentence, 'link' => true, 'muted' => false]
                : null,
            SecurityStatusType::CheckNeeded => ['text' => 'Check again: '.self::checkReason($security).'.', 'link' => true, 'muted' => true],
            SecurityStatusType::NotChecked => ['text' => SecuritySentences::headline($security), 'link' => false, 'muted' => true],
            default => null,
        };
    }

    /**
     * The check_needed reason, with the time of the oldest result for a stale one.
     */
    private static function checkReason(ResourceSecurity $security): string
    {
        $context = $security->checkContext;
        if (($context['time'] ?? null) === null && $security->checkedAt !== null) {
            $context['time'] = $security->checkedAt->toIso8601String();
        }

        return $security->checkReason === null
            ? 'the last check is incomplete'
            : SecuritySentences::checkReason($security->checkReason, $context);
    }

    /**
     * The UUIDs of the resources the user can see that need a security update now (update_now), sorted. Starts from
     * the current scans whose stored status has a fixed finding with an exploit signal, so only those resources are
     * loaded, not every resource of the team.
     *
     * @return list<string>
     */
    public static function updateNowResources(int $teamId): array
    {
        if (! instanceSettings()->is_image_scanning_allowed) {
            return [];
        }

        $owners = ImageScan::query()
            // Whether a resource is scanned is decided by the shared rule, not by the server of its scans.
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
            return [];
        }

        // A resource is update_now only when one of its images has a fixed finding with an exploit signal.
        $candidates = ImageScan::query()
            ->select(['id', 'resource_type', 'resource_id', 'summary'])
            ->whereIn('id', $currentIds)
            ->get()
            ->filter(fn (ImageScan $scan): bool => (int) data_get($scan->summary, 'security.facts.counts.signal_fixed', 0) > 0)
            ->groupBy('resource_type')
            ->map(fn (Collection $scans): array => $scans->pluck('resource_id')->unique()->values()->all());

        $resourceIds = [];
        foreach ($candidates as $type => $ids) {
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
            ->get())
            ->values();

        $statuses = SecurityStatus::storedMany($resources);

        return $resources
            ->filter(fn (Model $resource): bool => $statuses[ResourceScans::key($resource)]->status === SecurityStatusType::UpdateNow)
            ->map(fn (Model $resource): string => (string) $resource->getAttribute('uuid'))
            ->sort()
            ->values()
            ->all();
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
     * actionable match: fixed by an update, no fix yet, then ignored.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  int  $maxScans  At most this many matching scans are decoded; see `searchWasCapped()`.
     * @return Collection<int, array<string, mixed>> The entries with a `match` array.
     */
    public function search(Collection $entries, string $term, int $maxScans = self::MAX_DECODED_SCANS): Collection
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
            ->map(function (array $entry) use ($term, $findings, $ignores): ?array {
                $matches = [];
                foreach ($entry['scans'] as $scan) {
                    $loaded = $findings->get($scan->id);
                    if (! $loaded instanceof ImageScan) {
                        continue;
                    }

                    $scan->setAttribute('findings', $loaded->findings);
                    $matcher = $this->matcherFor($scan->resource, $ignores);
                    $classify = SecurityActions::fixStates($scan, $matcher);
                    foreach (ResourceScans::findings($scan) as $finding) {
                        if (! self::matches($finding, $term)) {
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

        $applications = Application::query()
            ->select(self::listColumns('applications'))
            ->selectRaw("CASE WHEN build_pack = 'dockercompose' THEN docker_compose END AS docker_compose")
            ->selectRaw("CASE WHEN build_pack = 'dockercompose' THEN docker_compose_raw END AS docker_compose_raw");

        return collect()
            ->concat($inTeam($applications)->with(['environment.project', 'settings', 'destination.server.settings'])->get())
            ->concat($inTeam(Service::query()->select(self::listColumns('services')))->with(['environment.project', 'destination.server.settings', 'server.settings'])->get())
            ->concat(collect(STANDALONE_DATABASE_MODELS)->flatMap(fn (string $class) => $inTeam($class::query())
                ->with(['environment.project', 'destination.server.settings'])
                ->get()))
            ->values();
    }

    /**
     * Every column of the table except UNREAD_COLUMNS, read once per request.
     *
     * @return list<string>
     */
    private static function listColumns(string $table): array
    {
        $columns = once(fn (): array => Schema::getColumnListing($table));

        return array_values(array_map(
            fn (string $column): string => "{$table}.{$column}",
            array_diff($columns, self::UNREAD_COLUMNS[$table] ?? []),
        ));
    }

    /**
     * The Security page of the resource.
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
