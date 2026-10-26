<?php

namespace App\Livewire\Fleet;

use App\Livewire\Concerns\BuildsMetricsChartPayload;
use App\Models\Application;
use App\Models\Server;
use App\Services\FleetMetricsAggregator;
use App\Services\SentinelMetricsClient;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Log;
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
        $this->loadData();
    }

    public function updatedLive(): void
    {
        if ($this->live) {
            $this->loadData();
        }
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
        $summaryTasks = [];
        foreach ($targets as $server) {
            // Coolify does not connect to an unreachable server, and its SSH retries would hold a worker past the process timeout.
            if (! $server->isFunctional()) {
                continue;
            }
            $client = $clients[$server->uuid] = $this->metricsClient($server);
            $summaryTasks[$server->uuid] = static fn () => $client->summary();
        }
        $summaries = $this->runMetricTasks($summaryTasks);
        $tasks = [];
        $range = $this->range;
        foreach ($clients as $uuid => $client) {
            $metrics = $summaries[$uuid] === null ? ['cpu', 'memory'] : ['cpu', 'memory', 'disk', 'load'];
            foreach ($metrics as $metric) {
                $tasks[$uuid.':'.$metric] = static fn () => $client->history($metric, $range);
            }
            if ($summaries[$uuid] !== null) {
                $tasks[$uuid.':containers'] = static fn () => $client->containersCurrent();
                $tasks[$uuid.':network'] = static fn () => $client->networkHistory($range);
            }
        }
        $results = $this->runMetricTasks($tasks);

        foreach ($targets as $server) {
            $summary = $summaries[$server->uuid] ?? null;
            foreach (['cpu', 'memory', 'disk', 'load'] as $metric) {
                $series[$metric][] = $results[$server->uuid.':'.$metric] ?? [];
            }

            if ($summary === null) {
                $rows[] = $this->rowWithoutMetrics($server);

                continue;
            }

            $current = $results[$server->uuid.':containers'] ?? [];
            $rows[] = array_merge(
                $summary,
                ['uuid' => $server->uuid, 'name' => $server->name, 'online' => true, 'reporting' => true, 'containers' => count($current)]
            );

            foreach ($current as $c) {
                // Names/images are resolved lazily for the displayed rows only (containerMeta),
                // so a server with many containers doesn't trigger a resolve storm here.
                $containers[] = array_merge($c, ['server' => $server->name, 'serverUuid' => $server->uuid]);
            }

            $net = $results[$server->uuid.':network'] ?? ['rx' => [], 'tx' => []];
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
     * Bound the number of PHP workers and SSH connections for large fleets.
     *
     * @param  array<string, \Closure>  $tasks
     * @return array<string, mixed>
     */
    protected function runMetricTasks(array $tasks): array
    {
        foreach ($tasks as $key => $task) {
            $tasks[$key] = static function () use ($task) {
                try {
                    return $task();
                } catch (\Throwable) {
                    return null;
                }
            };
        }
        $results = [];
        foreach (array_chunk($tasks, 8, true) as $batch) {
            try {
                $results += Concurrency::run($batch);
            } catch (\Throwable $e) {
                // A worker that runs past the process timeout fails its batch. Show those metrics as missing, not a server error.
                Log::warning('Fleet metrics batch failed', ['error' => $e->getMessage()]);
                $results += array_fill_keys(array_keys($batch), null);
            }
        }

        return $results;
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
        if (isset($this->containerMetaCache[$id])) {
            return $this->containerMetaCache[$id];
        }

        $teamId = currentTeam()?->id;
        $resource = $teamId !== null ? getResourceByUuid($id, $teamId) : null;

        $image = null;
        $link = null;
        if ($resource) {
            $image = data_get($resource, 'docker_registry_image_name') ?: data_get($resource, 'image');

            if ($resource instanceof Application && data_get($resource, 'environment.project.uuid')) {
                $link = route('project.application.metrics', [
                    'project_uuid' => $resource->environment->project->uuid,
                    'environment_uuid' => $resource->environment->uuid,
                    'application_uuid' => $resource->uuid,
                ]);
            }
        }

        return $this->containerMetaCache[$id] = [
            'name' => $resource?->name ?? $id,
            'image' => $image ?: null,
            'link' => $link,
        ];
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
