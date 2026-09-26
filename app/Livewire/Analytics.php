<?php

namespace App\Livewire;

use App\Livewire\Concerns\BuildsTrafficChartPayload;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Services\SentinelTrafficClient;
use App\Services\TrafficAnalyticsAggregator;
use App\Services\TrafficResource;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Lazy]
class Analytics extends Component
{
    use BuildsTrafficChartPayload;

    public string $chartId = 'global-analytics';

    public ?string $scopedServerUuid = null;

    /** Traffic-enabled servers owned by the current team. */
    public Collection $servers;

    /** @var array<string, string> uuid => name, for the server filter */
    public array $serverOptions = [];

    /** @var array<string, string> uuid => name, for the application/service filter (scoped to the selected server) */
    public array $appOptions = [];

    /**
     * Listbox options for the application/service filter, grouped under project headers so
     * it's clear which resource belongs to which project. Services carry a "(Service)" suffix.
     *
     * @var array<int, array{value: string, label: string, header?: bool}>
     */
    public array $appGroupedOptions = [];

    #[Url(as: 'range')]
    public string $range = '24h';

    #[Url(as: 'server')]
    public string $serverUuid = '';

    #[Url(as: 'app')]
    public string $appUuid = '';

    // Realtime refresh; off by default (click "Live" to arm it). Only meaningful on the
    // 24h range, which matches the 60s Sentinel cache TTL.
    public bool $live = false;

    public ?array $overview = null;

    public bool $latencyApproximate = false;

    public bool $uniquesApproximate = false;

    /** @var array<int, array<string, mixed>> */
    public array $topApps = [];

    /** @var array<int, array<string, mixed>> */
    public array $topHosts = [];

    /** @var array<int, array<string, mixed>> */
    public array $topPaths = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $breakdowns = [];

    public ?string $attribution = null;

    /**
     * Per-bucket status-class time series for the stacked area chart, summed across
     * target servers and sorted by bucket. Empty when no target Sentinel exposes the
     * series endpoint (older builds), which flips the chart back to the status donut.
     *
     * @var array<int, array{bucket: int, s2xx: int, s3xx: int, s4xx: int, s5xx: int}>
     */
    public array $series = [];

    public bool $hasSeries = false;

    /**
     * Servers that could run traffic analytics but have it off — drives the nudge banner.
     *
     * @var array<int, array{uuid: string, name: string}>
     */
    public array $eligibleDisabledServers = [];

    public string $nudgeKey = '';

    /**
     * Upper bound on per-app overviews fetched for the leaderboard, so a server with a huge
     * number of recorded apps can't reintroduce a per-app round-trip storm. Truncation is
     * logged (see loadData) rather than silently swallowed.
     */
    private const MAX_LEADERBOARD_APPS = 200;

    /** @var array<int, string> */
    protected array $breakdownDimensions = ['country', 'referer', 'browser', 'os', 'device', 'protocol', 'cache', 'status', 'agent', 'ip', 'useragent'];

    /**
     * Per-request cache of Sentinel key => display metadata, so resolving a name/domain/link
     * for the leaderboard and path domains runs at most once per key.
     *
     * @var array<string, array{uuid: string, name: string, domain: ?string, rowDomain: ?string, link: ?string, isService: bool}>
     */
    protected array $appMetaCache = [];

    /**
     * Per-request map of every team Application and Service by uuid; the only source used
     * to name Sentinel keys, so a key never resolves to another team's resource.
     *
     * @var array<string, TrafficResource>|null
     */
    protected ?array $teamResources = null;

    public function mount(?string $scopedServerUuid = null): void
    {
        $allServers = Server::ownedByCurrentTeamCached();

        $this->scopedServerUuid = $scopedServerUuid;

        if ($this->scopedServerUuid !== null) {
            $server = $allServers->firstWhere('uuid', $this->scopedServerUuid);
            abort_if($server === null, 404);

            $this->serverUuid = $server->uuid;
            $this->chartId = 'server-analytics-'.$server->uuid;
            $this->servers = $server->isTrafficAnalyticsEnabled() ? collect([$server]) : collect();
            $this->serverOptions = [$server->uuid => $server->name];
            $this->eligibleDisabledServers = [];
            $this->nudgeKey = '';
        } else {
            $this->servers = $allServers
                ->filter(fn (Server $server) => $server->isTrafficAnalyticsEnabled())
                ->values();

            $this->serverOptions = $this->servers
                ->mapWithKeys(fn (Server $server) => [$server->uuid => $server->name])
                ->all();

            $eligibleDisabled = $allServers
                ->filter(fn (Server $server) => ! $server->isTrafficAnalyticsEnabled()
                    && ! $server->isSwarm()
                    && ! $server->isBuildServer())
                ->values();

            $this->eligibleDisabledServers = $eligibleDisabled
                ->map(fn (Server $server) => ['uuid' => $server->uuid, 'name' => $server->name])
                ->all();
            $this->nudgeKey = substr(md5($eligibleDisabled->pluck('uuid')->sort()->implode(',')), 0, 12);

            // A bookmarked ?server= may point at a server that is no longer enabled.
            if ($this->serverUuid !== '' && ! array_key_exists($this->serverUuid, $this->serverOptions)) {
                $this->serverUuid = '';
            }
        }

        $this->refreshAppOptions();

        if ($this->appUuid !== '' && ! array_key_exists($this->appUuid, $this->appOptions)) {
            $this->appUuid = '';
        }

        if ($this->servers->isNotEmpty()) {
            $this->loadData();
        }
    }

    public function setRange(string $range): void
    {
        $this->range = in_array($range, ['24h', '7d', '30d'], true) ? $range : '24h';
        $this->loadData();
    }

    public function toggleLive(): void
    {
        if ($this->range !== '24h') {
            return;
        }
        $this->live = ! $this->live;
    }

    #[On('trafficAnalyticsStateChanged')]
    public function refreshTrafficAnalyticsState(): void
    {
        if ($this->scopedServerUuid === null) {
            return;
        }

        $server = Server::ownedByCurrentTeam()->whereUuid($this->scopedServerUuid)->firstOrFail();
        $this->overview = null;
        $this->servers = $server->isTrafficAnalyticsEnabled() ? collect([$server]) : collect();

        if ($this->servers->isNotEmpty()) {
            $this->refreshAppOptions();
            $this->loadData();
        }
    }

    public function isLivePollable(): bool
    {
        return $this->live && $this->range === '24h';
    }

    public function updatedServerUuid(): void
    {
        // Scope the app options to the newly selected server and drop an app filter
        // that no longer belongs to it.
        $this->refreshAppOptions();

        if ($this->appUuid !== '' && ! array_key_exists($this->appUuid, $this->appOptions)) {
            $this->appUuid = '';
        }

        $this->loadData();
    }

    public function updatedAppUuid(): void
    {
        $this->loadData();
    }

    protected function refreshAppOptions(): void
    {
        $enabledUuids = $this->servers->pluck('uuid');

        $resources = collect($this->teamResources())
            ->filter(function (TrafficResource $resource) use ($enabledUuids): bool {
                $serverUuid = $resource->server()?->uuid;

                if (! $serverUuid || ! $enabledUuids->contains($serverUuid)) {
                    return false;
                }

                return $this->serverUuid === '' || $serverUuid === $this->serverUuid;
            });

        // Flat uuid => name map, used to validate a bookmarked ?app= filter.
        $options = $resources->mapWithKeys(fn (TrafficResource $resource) => [$resource->uuid() => $resource->name()])->all();
        asort($options);
        $this->appOptions = $options;

        // Grouped listbox options: a header row per project, then its applications and
        // services (both alpha-sorted by name).
        $grouped = [];
        $byProject = $resources
            ->groupBy(fn (TrafficResource $resource) => $resource->projectName())
            ->sortKeys();

        foreach ($byProject as $projectName => $projectResources) {
            $grouped[] = ['value' => '__group_'.md5($projectName), 'label' => $projectName, 'header' => true];
            foreach ($projectResources->sortBy(fn (TrafficResource $resource) => $resource->name()) as $resource) {
                $grouped[] = [
                    'value' => $resource->uuid(),
                    'label' => $resource->isService() ? $resource->name().' (Service)' : $resource->name(),
                ];
            }
        }

        $this->appGroupedOptions = $grouped;
    }

    /**
     * Every Application and Service of the current team, keyed by uuid. Loaded once per
     * request with the relations the filter, links, and domain lookups need.
     *
     * @return array<string, TrafficResource>
     */
    protected function teamResources(): array
    {
        if ($this->teamResources !== null) {
            return $this->teamResources;
        }

        $resources = [];
        foreach (Application::ownedByCurrentTeam()->with(['environment.project', 'destination.server'])->get() as $application) {
            $resources[$application->uuid] = TrafficResource::for($application);
        }
        foreach (Service::ownedByCurrentTeam()->with(['environment.project', 'server', 'applications'])->get() as $service) {
            $resources[$service->uuid] = TrafficResource::for($service);
        }

        return $this->teamResources = $resources;
    }

    /**
     * The team resource selected in the application/service filter, or null.
     */
    protected function selectedResource(): ?TrafficResource
    {
        return $this->appUuid !== '' ? ($this->teamResources()[$this->appUuid] ?? null) : null;
    }

    /**
     * Servers this view should query, honoring the active server/app filters.
     */
    protected function targetServers(): Collection
    {
        if ($this->scopedServerUuid !== null) {
            return $this->servers;
        }

        if ($this->appUuid !== '') {
            $server = $this->selectedResource()?->server();

            return $server && $this->servers->contains(fn (Server $s) => $s->uuid === $server->uuid)
                ? collect([$server])
                : collect();
        }

        if ($this->serverUuid !== '') {
            return $this->servers->filter(fn (Server $s) => $s->uuid === $this->serverUuid)->values();
        }

        return $this->servers;
    }

    public function loadData(): void
    {
        if ($this->servers->isEmpty()) {
            return;
        }

        [$from, $to] = $this->window();
        // A selected application or service is queried under all of its Sentinel keys.
        $resource = $this->selectedResource();
        $servers = $this->targetServers();
        $domainForKey = fn (string $key): ?string => $this->appMeta($key)['domain'];

        $aggregator = new TrafficAnalyticsAggregator($this->breakdownDimensions);
        $appRows = [];
        $hostTotals = [];

        foreach ($servers as $server) {
            try {
                $client = $this->trafficClient($server);

                if ($resource !== null) {
                    $keys = $client->prefetchResource($resource->uuid(), $from, $to, $this->breakdownDimensions, $this->range);
                    foreach ($keys as $key) {
                        $aggregator->collect($client, $key, $from, $to, $this->range, $domainForKey);
                    }

                    continue;
                }

                // Warm every server-wide endpoint in one docker exec instead of ~15 serial
                // SSH round-trips; the per-call methods below then read from cache.
                $leaderboardKeys = $client->prefetchServerWide(null, $from, $to, $this->breakdownDimensions, $this->range, appsLimit: self::MAX_LEADERBOARD_APPS);

                if ($leaderboardKeys !== []) {
                    if (count($leaderboardKeys) > self::MAX_LEADERBOARD_APPS) {
                        Log::warning('Traffic analytics leaderboard truncated', [
                            'server' => $server->uuid,
                            'total' => count($leaderboardKeys),
                            'shown' => self::MAX_LEADERBOARD_APPS,
                        ]);
                        $leaderboardKeys = array_slice($leaderboardKeys, 0, self::MAX_LEADERBOARD_APPS);
                    }
                    // Warm the leaderboard's per-key overviews in a second batched exec.
                    $client->prefetchAppOverviews($leaderboardKeys, $from, $to);
                }

                $aggregator->collect($client, null, $from, $to, $this->range, $domainForKey);

                // Leaderboard: fold every key into its owning team resource (compose services
                // and previews into their application or service); unknown keys stay raw.
                foreach ($leaderboardKeys as $key) {
                    $keyOverview = $client->overview($key, $from, $to)->toArray();
                    $requests = (int) ($keyOverview['requests'] ?? 0);
                    $bandwidth = (int) ($keyOverview['bytesIn'] ?? 0) + (int) ($keyOverview['bytesOut'] ?? 0);
                    $meta = $this->appMeta($key);

                    $appRows[$meta['uuid']] ??= [
                        'uuid' => $meta['uuid'],
                        'name' => $meta['name'],
                        'domain' => $meta['rowDomain'],
                        'link' => $meta['link'],
                        'isService' => $meta['isService'],
                        'requests' => 0,
                        'bandwidth' => 0,
                    ];
                    $appRows[$meta['uuid']]['requests'] += $requests;
                    $appRows[$meta['uuid']]['bandwidth'] += $bandwidth;

                    // Top hosts: fold per-key volume up to the served hostname. Keys without
                    // a configured domain collapse into one "Unknown host" row.
                    $host = $meta['domain'] ?? '';
                    $hostTotals[$host] ??= ['host' => $host, 'requests' => 0, 'bandwidth' => 0];
                    $hostTotals[$host]['requests'] += $requests;
                    $hostTotals[$host]['bandwidth'] += $bandwidth;
                }
            } catch (\Throwable $e) {
                // Skip unreachable/failed servers so one bad server doesn't break the whole view.
                continue;
            }
        }

        if (! $aggregator->hasOverview()) {
            $this->resetData();
            // The chart lives under wire:ignore, so it only updates via this event — dispatch
            // even when cleared so a previously-populated chart flips to its no-data state
            // instead of keeping stale data.
            $this->dispatch("refreshChartData-{$this->chartId}-status", $this->chartPayload());

            return;
        }

        $result = $aggregator->overview();
        $this->overview = $result['overview']->toArray();
        $this->latencyApproximate = $result['latencyApproximate'];
        $this->uniquesApproximate = $result['uniquesApproximate'];

        $this->topApps = TrafficAnalyticsAggregator::topByRequests(array_values($appRows));
        $this->topHosts = TrafficAnalyticsAggregator::topByRequests(array_values($hostTotals));
        $this->topPaths = $aggregator->topPaths();
        $this->breakdowns = $aggregator->breakdowns();
        $this->attribution = $aggregator->attribution();
        $this->series = $aggregator->series();
        $this->hasSeries = $this->series !== [];

        $this->dispatch("refreshChartData-{$this->chartId}-status", $this->chartPayload());
    }

    /**
     * Payload for the status chart: the stacked-area time series when available,
     * plus the donut totals as a fallback for older Sentinel builds.
     *
     * @return array<string, mixed>
     */
    protected function chartPayload(): array
    {
        $device = $this->deviceChartData();
        $overview = $this->overview ?? [];

        return [
            'hasSeries' => $this->hasSeries,
            'range' => $this->range,
            'seriesData' => [
                $overview['s2xx'] ?? 0,
                $overview['s3xx'] ?? 0,
                $overview['s4xx'] ?? 0,
                $overview['s5xx'] ?? 0,
            ],
            'timeSeries' => [
                'categories' => array_column($this->series, 'bucket'),
                'requests' => $this->requestsSpark(),
                's2xx' => array_column($this->series, 's2xx'),
                's3xx' => array_column($this->series, 's3xx'),
                's4xx' => array_column($this->series, 's4xx'),
                's5xx' => array_column($this->series, 's5xx'),
            ],
            'requestsSpark' => $this->requestsSpark(),
            'sparkCategories' => array_column($this->series, 'bucket'),
            'errorsSpark' => $this->errorsSpark(),
            'bandwidthSpark' => $this->bandwidthSpark(),
            'uniquesSpark' => $this->uniquesSpark(),
            'latencySpark' => $this->latencySpark(),
            'geo' => $this->geoMarkers(),
            'deviceLabels' => $device['labels'],
            'deviceSeries' => $device['series'],
        ];
    }

    protected function resetData(): void
    {
        $this->overview = null;
        $this->latencyApproximate = false;
        $this->uniquesApproximate = false;
        $this->topApps = [];
        $this->topHosts = [];
        $this->topPaths = [];
        $this->breakdowns = [];
        $this->attribution = null;
        $this->series = [];
        $this->hasSeries = false;
    }

    public function errorRate(): float
    {
        if (! $this->overview || (int) ($this->overview['requests'] ?? 0) === 0) {
            return 0.0;
        }

        $errors = (int) ($this->overview['s4xx'] ?? 0) + (int) ($this->overview['s5xx'] ?? 0);

        return round(($errors / $this->overview['requests']) * 100, 2);
    }

    public function bandwidthBytes(): int
    {
        if (! $this->overview) {
            return 0;
        }

        return (int) ($this->overview['bytesIn'] ?? 0) + (int) ($this->overview['bytesOut'] ?? 0);
    }

    protected function trafficClient(Server $server): SentinelTrafficClient
    {
        return app(SentinelTrafficClient::class, ['server' => $server]);
    }

    /**
     * Resolve a Sentinel key to its owning team resource: row id (resource uuid), name,
     * key-specific domain (compose service / preview), primary domain for the leaderboard
     * row, and analytics link. Memoized per request. A key that no team resource owns
     * keeps the raw key as its name and no domain or link, so a Sentinel-reported key
     * never discloses another team's resource.
     *
     * @return array{uuid: string, name: string, domain: ?string, rowDomain: ?string, link: ?string, isService: bool}
     */
    protected function appMeta(string $key): array
    {
        if (isset($this->appMetaCache[$key])) {
            return $this->appMetaCache[$key];
        }

        $resources = $this->teamResources();
        $owner = null;
        foreach (SentinelTrafficClient::candidateOwnerUuids($key) as $candidate) {
            if (isset($resources[$candidate])) {
                $owner = $resources[$candidate];
                break;
            }
        }

        if ($owner === null) {
            return $this->appMetaCache[$key] = [
                'uuid' => $key,
                'name' => $key,
                'domain' => null,
                'rowDomain' => null,
                'link' => null,
                'isService' => false,
            ];
        }

        return $this->appMetaCache[$key] = [
            'uuid' => $owner->uuid(),
            'name' => $owner->name(),
            'domain' => $owner->domainForKey($key),
            'rowDomain' => $owner->primaryDomain(),
            'link' => $owner->analyticsLink(),
            'isService' => $owner->isService(),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function window(): array
    {
        $to = now();
        $from = match ($this->range) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDay(),
        };

        return [$from->toIso8601ZuluString(), $to->toIso8601ZuluString()];
    }

    public function placeholder(array $params = []): View
    {
        $scopedServerUuid = $params['scopedServerUuid'] ?? null;
        $hideSkeleton = false;

        if (is_string($scopedServerUuid)) {
            $server = Server::ownedByCurrentTeamCached()->firstWhere('uuid', $scopedServerUuid);
            $hideSkeleton = $server !== null && ! $server->isTrafficAnalyticsEnabled();
        }

        // Rendered instantly; the Sentinel round-trips run in the deferred lazy-load request.
        return view('livewire.analytics-placeholder', compact('hideSkeleton'));
    }

    public function render()
    {
        return view('livewire.analytics');
    }
}
