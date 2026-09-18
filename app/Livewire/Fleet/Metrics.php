<?php

namespace App\Livewire\Fleet;

use App\Livewire\Concerns\BuildsMetricsChartPayload;
use App\Models\Application;
use App\Models\Server;
use App\Services\FleetMetricsAggregator;
use App\Services\SentinelMetricsClient;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Lazy]
class Metrics extends Component
{
    use BuildsMetricsChartPayload;

    public string $chartId = 'fleet-metrics';

    /** Metrics-enabled servers owned by the current team. */
    public Collection $servers;

    /** @var array<string, string> uuid => name */
    public array $serverOptions = [];

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
        $allServers = Server::ownedByCurrentTeamCached();

        $this->servers = $allServers
            ->filter(fn (Server $server) => $server->isMetricsEnabled())
            ->values();

        $this->serverOptions = $this->servers
            ->mapWithKeys(fn (Server $server) => [$server->uuid => $server->name])
            ->all();

        if ($this->serverUuid !== '' && ! array_key_exists($this->serverUuid, $this->serverOptions)) {
            $this->serverUuid = '';
        }

        $this->normalizeContainerMetric();

        if ($this->servers->isNotEmpty()) {
            $this->loadData();
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

    public function isLivePollable(): bool
    {
        return $this->live && $this->range === '24h';
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
        if ($this->servers->isEmpty()) {
            return;
        }

        $rows = [];
        $containers = [];
        $series = ['cpu' => [], 'memory' => [], 'disk' => [], 'load' => [], 'networkRx' => [], 'networkTx' => []];

        foreach ($this->targetServers() as $server) {
            $client = $this->metricsClient($server);

            try {
                $summary = $client->summary();
            } catch (\Throwable) {
                $summary = null;
            }

            if ($summary === null) {
                $rows[] = $this->offlineRow($server);

                continue;
            }

            $current = $client->containersCurrent();
            $rows[] = array_merge(
                ['uuid' => $server->uuid, 'name' => $server->name, 'online' => true, 'containers' => count($current)],
                $summary
            );

            foreach ($current as $c) {
                // Names/images are resolved lazily for the displayed rows only (containerMeta),
                // so a server with many containers doesn't trigger a resolve storm here.
                $containers[] = array_merge($c, ['server' => $server->name, 'serverUuid' => $server->uuid]);
            }

            $series['cpu'][] = $client->history('cpu', $this->range);
            $series['memory'][] = $client->history('memory', $this->range);
            $series['disk'][] = $client->history('disk', $this->range);
            $series['load'][] = $client->history('load', $this->range);
            $net = $client->networkHistory($this->range);
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
     * @return array<string, mixed>
     */
    protected function offlineRow(Server $server): array
    {
        return [
            'uuid' => $server->uuid, 'name' => $server->name, 'online' => false,
            'cpu' => null, 'memUsed' => null, 'memTotal' => null,
            'diskUsed' => null, 'diskTotal' => null, 'diskPercent' => null,
            'load1' => null, 'netRx' => null, 'netTx' => null, 'containers' => null,
        ];
    }

    protected function metricsClient(Server $server): SentinelMetricsClient
    {
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
        $resource = $teamId ? getResourceByUuid($id, $teamId) : null;

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
