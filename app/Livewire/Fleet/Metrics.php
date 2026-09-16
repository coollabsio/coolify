<?php

namespace App\Livewire\Fleet;

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

    public bool $live = false;

    /** @var array<string, mixed> */
    public array $kpis = [];

    /** @var array<int, array<string, mixed>> */
    public array $serverRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $topContainers = [];

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
        $this->loadData();
    }

    public function setRange(string $range): void
    {
        $this->range = in_array($range, ['24h', '7d', '30d'], true) ? $range : '24h';
        $this->loadData();
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
                $meta = $this->resolveContainer($server, $c['id']);
                $containers[] = array_merge($c, ['server' => $server->name, 'name' => $meta['name'], 'link' => $meta['link']]);
            }
        }

        $this->serverRows = $rows;
        $this->kpis = FleetMetricsAggregator::fleetKpis($rows);
        $this->topContainers = FleetMetricsAggregator::rankContainers($containers, $this->containerMetric);

        $this->dispatch("refreshChartData-{$this->chartId}", $this->chartPayload());
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
     * team-owned resource name + metrics link, memoized. Shows the raw id when no
     * team-owned resource matches, so a Sentinel id never discloses another team's name.
     *
     * @return array{name: string, link: ?string}
     */
    protected function resolveContainer(Server $server, string $id): array
    {
        if (isset($this->containerMetaCache[$id])) {
            return $this->containerMetaCache[$id];
        }

        $app = Application::ownedByCurrentTeam()->with('environment.project')->whereUuid($id)->first();

        $link = null;
        if ($app && data_get($app, 'environment.project.uuid')) {
            $link = route('project.application.metrics', [
                'project_uuid' => $app->environment->project->uuid,
                'environment_uuid' => $app->environment->uuid,
                'application_uuid' => $app->uuid,
            ]);
        }

        return $this->containerMetaCache[$id] = [
            'name' => $app?->name ?? $id,
            'link' => $link,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function chartPayload(): array
    {
        return ['range' => $this->range];
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
