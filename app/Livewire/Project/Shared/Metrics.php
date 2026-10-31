<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Metrics extends Component
{
    public $resource;

    public $chartId = 'metrics';

    public $data;

    public $categories;

    public int $interval = 5;

    public bool $live = true;

    /**
     * Running containers of a Docker Compose application: compose service names keyed by Sentinel container name.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $containers = [];

    #[Locked]
    public bool $containersLoaded = false;

    public ?string $container = null;

    public function loadData()
    {
        try {
            $container = null;
            if ($this->isDockerCompose()) {
                $this->loadContainers();
                if (! array_key_exists($this->container ?? '', $this->containers)) {
                    return;
                }
                $container = $this->container;
            }

            $this->resource->prefetchMetrics(['cpu', 'memory'], $this->interval, $container);
            $cpuMetrics = $this->resource->getCpuMetrics($this->interval, $container);
            $memoryMetrics = $this->resource->getMemoryMetrics($this->interval, $container);
            $this->dispatch("refreshChartData-{$this->chartId}-cpu", [
                'seriesData' => $cpuMetrics,
            ]);
            $this->dispatch("refreshChartData-{$this->chartId}-memory", [
                'seriesData' => $memoryMetrics,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function setInterval()
    {
        $this->loadData();
    }

    /**
     * Ranges up to one hour refresh live by default. Longer ranges load once, because each
     * refresh fetches the full range from Sentinel over SSH; the user can still turn live on.
     */
    public function updatedInterval(): void
    {
        $this->live = $this->interval <= 60;
    }

    public function updatedLive(): void
    {
        if ($this->live) {
            $this->pollData();
        }
    }

    /**
     * The poll target. The server decides the refresh rate, so a client cannot fetch faster
     * than the range allows or while live refresh is off.
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
     * Seconds between live refreshes, or null when live refresh is off. Short ranges refresh
     * every 5 seconds; longer ranges change slowly, so they refresh every minute.
     */
    public function pollSeconds(): ?int
    {
        if (! $this->live) {
            return null;
        }

        return $this->interval <= 60 ? 5 : 60;
    }

    /**
     * Docker Compose containers are recreated on every deployment, so a refresh can briefly find
     * none of them running. Compose applications count as running unless their status is exited,
     * which keeps the charts and the live refresh in place until the containers are back.
     */
    public function isRunning(): bool
    {
        if ($this->isDockerCompose()) {
            return ! str($this->resource->status)->startsWith('exited');
        }

        return str($this->resource->status)->contains('running');
    }

    private function isDockerCompose(): bool
    {
        return $this->resource instanceof Application && $this->resource->build_pack === 'dockercompose';
    }

    private function loadContainers(): void
    {
        $server = $this->resource->destination->server;
        $containers = $server->isFunctional()
            ? getCurrentApplicationContainerStatus($server, $this->resource, 0)
            : collect();

        $this->containers = mapComposeContainersToMetricsNames($containers);
        $this->containersLoaded = true;
        if ($this->containers !== [] && ! array_key_exists($this->container ?? '', $this->containers)) {
            $this->container = array_key_first($this->containers);
        }
    }

    public function render()
    {
        return view('livewire.project.shared.metrics', [
            'containerOptions' => collect($this->containers)
                ->map(fn ($service, $container) => ['value' => $container, 'label' => $service])
                ->values()
                ->all(),
        ]);
    }
}
