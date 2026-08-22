<?php

namespace App\Livewire;

use App\Livewire\Concerns\BuildsTrafficChartPayload;
use App\Models\Application;
use App\Models\Server;
use App\Services\SentinelTrafficClient;
use App\Services\TrafficAnalyticsAggregator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Lazy]
class Analytics extends Component
{
    use BuildsTrafficChartPayload;

    public string $chartId = 'global-analytics';

    /** Traffic-enabled servers owned by the current team. */
    public Collection $servers;

    /** @var array<string, string> uuid => name, for the server filter */
    public array $serverOptions = [];

    /** @var array<string, string> uuid => name, for the application filter (scoped to the selected server) */
    public array $appOptions = [];

    /**
     * Listbox options for the application filter, grouped under project headers so
     * it's clear which application belongs to which project.
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
     * Per-request cache of app uuid => display metadata, so resolving a name/domain/link
     * for the leaderboard and path domains hits the DB at most once per app.
     *
     * @var array<string, array{name: string, domain: ?string, link: ?string}>
     */
    protected array $appMetaCache = [];

    public function mount(): void
    {
        $allServers = Server::ownedByCurrentTeamCached();

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

        $apps = Application::ownedByCurrentTeam()->with(['environment.project', 'destination.server'])->get()
            ->filter(function (Application $app) use ($enabledUuids): bool {
                $serverUuid = $app->destination?->server?->uuid;

                if (! $serverUuid || ! $enabledUuids->contains($serverUuid)) {
                    return false;
                }

                return $this->serverUuid === '' || $serverUuid === $this->serverUuid;
            });

        // Flat uuid => name map, used to validate a bookmarked ?app= filter.
        $options = $apps->mapWithKeys(fn (Application $app) => [$app->uuid => $app->name])->all();
        asort($options);
        $this->appOptions = $options;

        // Grouped listbox options: a header row per project, then its apps (both alpha-sorted).
        $grouped = [];
        $byProject = $apps
            ->groupBy(fn (Application $app) => (string) (data_get($app, 'environment.project.name') ?: 'Ungrouped'))
            ->sortKeys();

        foreach ($byProject as $projectName => $projectApps) {
            $grouped[] = ['value' => '__group_'.md5($projectName), 'label' => $projectName, 'header' => true];
            foreach ($projectApps->sortBy('name') as $app) {
                $grouped[] = ['value' => $app->uuid, 'label' => $app->name];
            }
        }

        $this->appGroupedOptions = $grouped;
    }

    /**
     * Servers this view should query, honoring the active server/app filters.
     */
    protected function targetServers(): Collection
    {
        if ($this->appUuid !== '') {
            $server = Application::ownedByCurrentTeam()->whereUuid($this->appUuid)->first()
                ?->destination?->server;

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
        $appKey = $this->appUuid !== '' ? $this->appUuid : null;
        $servers = $this->targetServers();

        $overviews = [];
        $appRows = [];
        $pathTotals = [];
        $breakdownTotals = array_fill_keys($this->breakdownDimensions, []);
        $seriesByBucket = [];
        $attribution = null;

        foreach ($servers as $server) {
            try {
                $client = $this->trafficClient($server);

                // Warm every server-wide endpoint in one docker exec instead of ~15 serial
                // SSH round-trips; the per-call methods below then read from cache.
                $leaderboardUuids = $client->prefetchServerWide($appKey, $from, $to, $this->breakdownDimensions, $this->range, appsLimit: self::MAX_LEADERBOARD_APPS);

                // Per-application leaderboard only makes sense when not already filtered to one app.
                if ($appKey === null && $leaderboardUuids !== []) {
                    if (count($leaderboardUuids) > self::MAX_LEADERBOARD_APPS) {
                        Log::warning('Traffic analytics leaderboard truncated', [
                            'server' => $server->uuid,
                            'total' => count($leaderboardUuids),
                            'shown' => self::MAX_LEADERBOARD_APPS,
                        ]);
                        $leaderboardUuids = array_slice($leaderboardUuids, 0, self::MAX_LEADERBOARD_APPS);
                    }
                    // Warm the leaderboard's per-app overviews in a second batched exec.
                    $client->prefetchAppOverviews($leaderboardUuids, $from, $to);
                }

                $overviews[] = $client->overview($appKey, $from, $to);

                if ($appKey === null) {
                    foreach ($leaderboardUuids as $uuid) {
                        $appOverview = $client->overview($uuid, $from, $to)->toArray();
                        $meta = $this->appMeta($uuid);

                        $appRows[] = [
                            'uuid' => $uuid,
                            'name' => $meta['name'],
                            'domain' => $meta['domain'],
                            'link' => $meta['link'],
                            'requests' => (int) ($appOverview['requests'] ?? 0),
                            'bandwidth' => (int) ($appOverview['bytesIn'] ?? 0) + (int) ($appOverview['bytesOut'] ?? 0),
                        ];
                    }
                }

                foreach ($client->paths($appKey, $from, $to, 50) as $path) {
                    $data = $path->toArray();
                    $pathStr = (string) ($data['path'] ?? '');
                    // Prefer the per-path app from Sentinel; fall back to the active app filter
                    // (older Sentinel omits `app`, but a filtered view still knows the app).
                    $appId = (string) ($data['app'] ?? '');
                    $resolveId = $appId !== '' ? $appId : ($appKey ?? '');
                    // Key by (app, path) so the same path under two apps stays two rows, each
                    // carrying its own domain.
                    $key = $resolveId."\n".$pathStr;
                    $domain = $resolveId !== '' ? ($this->appMeta($resolveId)['domain'] ?? null) : null;

                    $pathTotals[$key] ??= ['path' => $pathStr, 'domain' => $domain, 'requests' => 0, 'bytesOut' => 0, 'p95' => 0.0];
                    $pathTotals[$key]['requests'] += (int) ($data['requests'] ?? 0);
                    $pathTotals[$key]['bytesOut'] += (int) ($data['bytesOut'] ?? 0);
                    $pathTotals[$key]['p95'] = max($pathTotals[$key]['p95'], (float) ($data['p95'] ?? 0));
                }

                foreach ($this->breakdownDimensions as $dimension) {
                    foreach ($client->breakdown($appKey, $dimension, $from, $to, 50) as $row) {
                        $data = $row->toArray();
                        $value = (string) ($data['value'] ?? '');

                        $breakdownTotals[$dimension][$value] ??= ['value' => $value, 'requests' => 0, 'bytesOut' => 0];
                        $breakdownTotals[$dimension][$value]['requests'] += (int) ($data['requests'] ?? 0);
                        $breakdownTotals[$dimension][$value]['bytesOut'] += (int) ($data['bytesOut'] ?? 0);
                    }
                }

                $attribution ??= $client->attribution();

                // Per-bucket status series; summed by bucket across servers. Isolated so a
                // series hiccup (or an older Sentinel lacking the endpoint) never discards a
                // server's other data — an empty result simply flips the chart to the donut.
                try {
                    foreach ($client->series($appKey, $this->range) as $bucket) {
                        $data = $bucket->toArray();
                        $ts = (int) ($data['bucket'] ?? 0);

                        $seriesByBucket[$ts] ??= ['bucket' => $ts, 's2xx' => 0, 's3xx' => 0, 's4xx' => 0, 's5xx' => 0, 'requests' => 0, 'bytesIn' => 0, 'bytesOut' => 0, 'uniqueVisitors' => 0, 'p95' => 0.0];
                        $seriesByBucket[$ts]['s2xx'] += (int) ($data['s2xx'] ?? 0);
                        $seriesByBucket[$ts]['s3xx'] += (int) ($data['s3xx'] ?? 0);
                        $seriesByBucket[$ts]['s4xx'] += (int) ($data['s4xx'] ?? 0);
                        $seriesByBucket[$ts]['s5xx'] += (int) ($data['s5xx'] ?? 0);
                        $seriesByBucket[$ts]['requests'] += (int) ($data['requests'] ?? 0);
                        $seriesByBucket[$ts]['bytesIn'] += (int) ($data['bytesIn'] ?? 0);
                        $seriesByBucket[$ts]['bytesOut'] += (int) ($data['bytesOut'] ?? 0);
                        // Uniques summed across servers (approximate); p95 takes the worst bucket.
                        $seriesByBucket[$ts]['uniqueVisitors'] += (int) ($data['uniqueVisitors'] ?? 0);
                        $seriesByBucket[$ts]['p95'] = max($seriesByBucket[$ts]['p95'], (float) ($data['p95'] ?? 0));
                    }
                } catch (\Throwable $e) {
                    // Leave this server out of the series; donut fallback covers it.
                }
            } catch (\Throwable $e) {
                // Skip unreachable/failed servers so one bad server doesn't break the whole view.
                continue;
            }
        }

        if (empty($overviews)) {
            $this->resetData();

            return;
        }

        $result = TrafficAnalyticsAggregator::sumOverviews($overviews);
        $this->overview = $result['overview']->toArray();
        $this->latencyApproximate = $result['latencyApproximate'];
        $this->uniquesApproximate = $result['uniquesApproximate'];

        usort($appRows, fn ($a, $b) => $b['requests'] <=> $a['requests']);
        $this->topApps = array_slice($appRows, 0, 50);

        // Top hosts: fold per-app volume up to the served hostname (an app's primary
        // domain). Apps without a configured FQDN collapse into one "Unknown host" row.
        $hostTotals = [];
        foreach ($appRows as $row) {
            $host = $row['domain'] ?? '';
            $hostTotals[$host] ??= ['host' => $host, 'requests' => 0, 'bandwidth' => 0];
            $hostTotals[$host]['requests'] += (int) $row['requests'];
            $hostTotals[$host]['bandwidth'] += (int) $row['bandwidth'];
        }
        $hosts = array_values($hostTotals);
        usort($hosts, fn ($a, $b) => $b['requests'] <=> $a['requests']);
        $this->topHosts = array_slice($hosts, 0, 50);

        $paths = array_values($pathTotals);
        usort($paths, fn ($a, $b) => $b['requests'] <=> $a['requests']);
        $this->topPaths = array_slice($paths, 0, 50);

        $breakdowns = [];
        foreach ($this->breakdownDimensions as $dimension) {
            $rows = array_values($breakdownTotals[$dimension]);
            usort($rows, fn ($a, $b) => $b['requests'] <=> $a['requests']);
            $breakdowns[$dimension] = array_slice($rows, 0, 50);
        }
        $this->breakdowns = $breakdowns;

        $this->attribution = $attribution;

        ksort($seriesByBucket);
        $this->series = array_values($seriesByBucket);
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

        return [
            'hasSeries' => $this->hasSeries,
            'range' => $this->range,
            'seriesData' => [
                $this->overview['s2xx'] ?? 0,
                $this->overview['s3xx'] ?? 0,
                $this->overview['s4xx'] ?? 0,
                $this->overview['s5xx'] ?? 0,
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
     * Resolve an app uuid to its display name, primary domain, and analytics-page link,
     * memoized per request. Returns the uuid as the name for apps not owned by the team
     * so a Sentinel-reported uuid never discloses another team's application name.
     *
     * @return array{name: string, domain: ?string, link: ?string}
     */
    protected function appMeta(string $uuid): array
    {
        if (isset($this->appMetaCache[$uuid])) {
            return $this->appMetaCache[$uuid];
        }

        $app = Application::ownedByCurrentTeam()->with('environment.project')->whereUuid($uuid)->first();

        $domain = null;
        if ($app) {
            $first = collect($app->fqdns)->first();
            $domain = $first ? (parse_url($first, PHP_URL_HOST) ?: null) : null;
        }

        $link = null;
        if ($app && data_get($app, 'environment.project.uuid')) {
            $link = route('project.application.analytics', [
                'project_uuid' => $app->environment->project->uuid,
                'environment_uuid' => $app->environment->uuid,
                'application_uuid' => $app->uuid,
            ]);
        }

        return $this->appMetaCache[$uuid] = [
            'name' => $app?->name ?? $uuid,
            'domain' => $domain,
            'link' => $link,
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

    public function placeholder(): View
    {
        // Rendered instantly; the Sentinel round-trips run in the deferred lazy-load request.
        return view('livewire.analytics-placeholder');
    }

    public function render()
    {
        return view('livewire.analytics');
    }
}
