<?php

namespace App\Livewire\Fleet;

use App\Livewire\Concerns\BuildsMetricsChartPayload;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Services\FleetMetricsAggregator;
use App\Services\SentinelMetricsClient;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Lazy]
class Metrics extends Component
{
    use AuthorizesRequests;
    use BuildsMetricsChartPayload;

    public string $chartId = 'fleet-metrics';

    /** Metrics-enabled servers owned by the current team. */
    public Collection $servers;

    /** @var array<string, string> uuid => name */
    public array $serverOptions = [];

    /** @var array<int, string> Names of the servers where the user can turn metrics on; filled only when no server has metrics. */
    public array $enableableServers = [];

    #[Url(as: 'range')]
    public string $range = '24h';

    #[Url(as: 'server')]
    public string $serverUuid = '';

    #[Url(as: 'cmetric')]
    public string $containerMetric = 'cpu';

    private const CONTAINER_METRICS = ['cpu', 'memory', 'disk', 'network'];

    public bool $live = false;

    /** @var array<string, mixed> */
    public array $kpis = [];

    /** @var array<int, array<string, mixed>> */
    public array $serverRows = [];

    /** @var array<int, array<string, mixed>> All fetched containers, kept so the metric toggle re-ranks without re-fetching. */
    public array $containersRaw = [];

    /** @var array<int, array<string, mixed>> */
    public array $topContainers = [];

    /** @var array<string, mixed> Trend series for the charts, also embedded for first paint. */
    public array $chartData = [];

    /** @var array<string, array{name: string, link: ?string}> */
    protected array $containerMetaCache = [];

    public function mount(): void
    {
        $this->refreshServers();

        $this->normalizeContainerMetric();

        if ($this->servers->isNotEmpty()) {
            $this->loadData();
        }
    }

    public function hydrate(): void
    {
        $previous = array_column($this->serverRows, 'uuid');
        $this->refreshServers();
        $current = $this->targetServers()->pluck('uuid')->all();
        sort($previous);
        sort($current);

        if ($previous !== $current) {
            $this->loadData();
        }
    }

    private function refreshServers(): void
    {
        abort_unless(currentTeam() !== null, 403);
        $teamServers = Server::ownedByCurrentTeam()->get();
        $this->servers = $teamServers
            ->filter(fn (Server $server) => $server->isMetricsEnabled())
            ->values();
        $this->enableableServers = $this->servers->isEmpty()
            ? $this->serversWithoutMetrics($teamServers)->pluck('name')->all()
            : [];

        foreach ($this->servers as $server) {
            $this->authorize('view', $server);
        }

        $this->serverOptions = $this->servers
            ->mapWithKeys(fn (Server $server) => [$server->uuid => $server->name])
            ->all();

        if ($this->serverUuid !== '' && ! array_key_exists($this->serverUuid, $this->serverOptions)) {
            $this->serverUuid = '';
        }
    }

    /**
     * Servers that can report metrics, have them off, are reachable, and that the user can change.
     * An unreachable server cannot start Sentinel, so it stays out.
     *
     * @param  Collection<int, Server>  $teamServers
     * @return Collection<int, Server>
     */
    private function serversWithoutMetrics(Collection $teamServers): Collection
    {
        return $teamServers
            ->filter(fn (Server $server) => ! $server->isMetricsEnabled()
                && $server->isSentinelEnabled()
                && auth()->user()->can('update', $server)
                && $server->isFunctional())
            ->values();
    }

    public function enableMetrics(): void
    {
        try {
            abort_unless(currentTeam() !== null, 403);
            $servers = $this->serversWithoutMetrics(Server::ownedByCurrentTeam()->get());
            if ($servers->isEmpty()) {
                return;
            }

            // One failed server must not stop the others, so each server has its own try.
            $failed = [];
            foreach ($servers as $server) {
                try {
                    $server->settings->is_metrics_enabled = true;
                    // Saved quietly: Sentinel is started below, so the settings hook must not start it a second time.
                    $server->settings->saveQuietly();
                    auditLog('ui.server.sentinel.updated', [
                        'team_id' => $server->team_id,
                        'server_uuid' => $server->uuid,
                        'server_name' => $server->name,
                        'changed_fields' => ['is_metrics_enabled'],
                    ]);
                    $server->restartSentinel();
                } catch (\Throwable $e) {
                    report($e);
                    $failed[] = $server->name;
                }
            }

            $this->loadData();
            $enabled = $servers->count() - count($failed);
            if ($enabled > 0) {
                $this->dispatch('success', 'Metrics enabled on '.$enabled.' '.str('server')->plural($enabled).'. Sentinel is starting, data shows in about one minute.');
            }
            if ($failed !== []) {
                $this->dispatch('error', 'Could not enable metrics on '.implode(', ', $failed).'. Check the server settings and try again.');
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function updatedServerUuid(): void
    {
        $this->loadData();
    }

    public function updatedContainerMetric(): void
    {
        $this->normalizeContainerMetric();

        // Re-rank the already-fetched containers; no need to re-hit Sentinel.
        $this->topContainers = FleetMetricsAggregator::rankContainers($this->containersRaw, $this->containerMetric);
    }

    private function normalizeContainerMetric(): void
    {
        if (! in_array($this->containerMetric, self::CONTAINER_METRICS, true)) {
            $this->containerMetric = 'cpu';
        }
    }

    public function setRange(string $range): void
    {
        $this->range = in_array($range, ['24h', '7d', '30d'], true) ? $range : '24h';
        // Every refresh fetches the full range from each server over SSH, so a new range starts paused.
        $this->live = false;
        $this->loadData();
    }

    public function updatedLive(): void
    {
        if ($this->live) {
            $this->pollData();
        }
    }

    /**
     * The poll target. The server decides the refresh rate, so a client cannot fetch faster
     * than once a minute or while live refresh is off.
     */
    public function pollData(): void
    {
        $seconds = $this->pollSeconds();
        if ($seconds === null) {
            return;
        }

        // One second of slack, so a poll that fires slightly early is not dropped.
        if (RateLimiter::attempt('metrics-poll:'.$this->getId(), 1, fn () => true, $seconds - 1) === false) {
            return;
        }

        $this->loadData();
    }

    /**
     * Seconds between live refreshes, or null when live refresh is off.
     */
    public function pollSeconds(): ?int
    {
        return $this->live ? 60 : null;
    }

    /**
     * @return Collection<int, Server>
     */
    protected function targetServers(): Collection
    {
        if ($this->serverUuid !== '') {
            return $this->servers->filter(fn (Server $s) => $s->uuid === $this->serverUuid)->values();
        }

        return $this->servers;
    }

    public function loadData(): void
    {
        $this->refreshServers();
        $this->normalizeContainerMetric();

        $rows = [];
        $containers = [];
        $series = ['cpu' => [], 'memory' => [], 'disk' => [], 'load' => [], 'networkRx' => [], 'networkTx' => []];

        $targets = $this->targetServers();
        $clients = [];
        foreach ($targets as $server) {
            // Coolify does not connect to an unreachable server.
            if ($server->isFunctional()) {
                $clients[$server->uuid] = $this->metricsClient($server);
            }
        }
        SentinelMetricsClient::prefetchConcurrently($clients, $this->range);

        foreach ($targets as $server) {
            $client = $clients[$server->uuid] ?? null;
            $summary = $client === null ? null : $this->attempt(fn () => $client->summary());
            foreach (['cpu', 'memory', 'disk', 'load'] as $metric) {
                // A Sentinel without a summary endpoint still has cpu and memory history.
                $read = $client !== null && ($summary !== null || in_array($metric, ['cpu', 'memory'], true));
                $series[$metric][] = $read ? ($this->attempt(fn () => $client->history($metric, $this->range)) ?? []) : [];
            }

            if ($summary === null) {
                $rows[] = $this->rowWithoutMetrics($server);

                continue;
            }

            $current = $this->attempt(fn () => $client->containersCurrent()) ?? [];
            $rows[] = array_merge(
                $summary,
                ['uuid' => $server->uuid, 'name' => $server->name, 'online' => true, 'reporting' => true, 'containers' => count($current)]
            );

            foreach ($current as $c) {
                $containers[] = array_merge($c, ['server' => $server->name, 'serverUuid' => $server->uuid]);
            }

            $net = $this->attempt(fn () => $client->networkHistory($this->range)) ?? ['rx' => [], 'tx' => []];
            $series['networkRx'][] = $net['rx'];
            $series['networkTx'][] = $net['tx'];
        }

        $this->serverRows = $rows;
        $this->kpis = FleetMetricsAggregator::fleetKpis($rows);
        $this->containersRaw = $containers;
        $this->topContainers = FleetMetricsAggregator::rankContainers($containers, $this->containerMetric);
        $this->chartData = $this->buildChartPayload($series);

        $this->dispatch("refreshChartData-{$this->chartId}", $this->chartData);
    }

    /**
     * One failed metric must not fail the others.
     */
    private function attempt(\Closure $read): mixed
    {
        try {
            return $read();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A server whose Sentinel returned no current metrics. It is online when Coolify can reach it,
     * because a failed metrics request does not mean that the server is down.
     *
     * @return array<string, mixed>
     */
    protected function rowWithoutMetrics(Server $server): array
    {
        return [
            'uuid' => $server->uuid, 'name' => $server->name, 'online' => (bool) $server->isFunctional(), 'reporting' => false,
            'cpu' => null, 'memUsed' => null, 'memTotal' => null,
            'diskUsed' => null, 'diskTotal' => null, 'diskPercent' => null,
            'load1' => null, 'netRx' => null, 'netTx' => null, 'containers' => null,
        ];
    }

    protected function metricsClient(Server $server): SentinelMetricsClient
    {
        $server->settings->ensureValidSentinelToken();

        return app(SentinelMetricsClient::class, ['server' => $server]);
    }

    /**
     * Resolve a Sentinel container id (the resource uuid Sentinel keys by) to a
     * team-owned resource name, docker image, and metrics link, memoized. Shows the raw
     * id and no image when no team-owned resource matches, so a Sentinel id never
     * discloses another team's resource.
     *
     * @return array{name: string, image: ?string, link: ?string}
     */
    public function containerMeta(string $id): array
    {
        if (! isset($this->containerMetaCache[$id])) {
            // Resolve every listed container at once, not one lookup per table row.
            $this->resolveContainerMeta([...array_column($this->containersRaw, 'id'), $id]);
        }

        return $this->containerMetaCache[$id];
    }

    /**
     * @param  array<int, string>  $ids
     */
    private function resolveContainerMeta(array $ids): void
    {
        $ids = array_values(array_diff(array_unique($ids), array_keys($this->containerMetaCache)));
        $teamId = currentTeam()?->id;
        $resources = $teamId === null ? [] : $this->teamResourcesByUuid($ids, $teamId);

        foreach ($ids as $id) {
            $resource = $resources[$id] ?? null;
            $image = $resource ? (data_get($resource, 'docker_registry_image_name') ?: data_get($resource, 'image')) : null;
            $link = null;
            if ($resource instanceof Application && data_get($resource, 'environment.project.uuid')) {
                $link = route('project.application.metrics', [
                    'project_uuid' => $resource->environment->project->uuid,
                    'environment_uuid' => $resource->environment->uuid,
                    'application_uuid' => $resource->uuid,
                ]);
            }

            $this->containerMetaCache[$id] = [
                'name' => $resource?->name ?? $id,
                'image' => $image ?: null,
                'link' => $link,
            ];
        }
    }

    /**
     * The team's resources with these uuids, one query per resource type.
     *
     * @param  array<int, string>  $uuids
     * @return array<string, mixed> uuid => resource
     */
    private function teamResourcesByUuid(array $uuids, int $teamId): array
    {
        $found = [];
        foreach ([Application::class, Service::class, ...array_values(STANDALONE_DATABASE_MODELS), ServiceDatabase::class] as $model) {
            $missing = array_values(array_diff($uuids, array_keys($found)));
            if ($missing === []) {
                break;
            }
            $teamPath = $model === ServiceDatabase::class ? 'service.environment.project' : 'environment.project';
            $model::query()
                ->whereIn('uuid', $missing)
                ->whereRelation($teamPath, 'team_id', $teamId)
                ->with($teamPath)
                ->get()
                ->each(function ($resource) use (&$found) {
                    $found[$resource->uuid] = $resource;
                });
        }

        return $found;
    }

    public function placeholder(): View
    {
        return view('livewire.fleet.metrics-placeholder');
    }

    public function render(): View
    {
        return view('livewire.fleet.metrics');
    }
}
