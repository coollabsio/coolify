<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Metrics extends Component
{
    public $resource;

    public $chartId = 'metrics';

    public $data;

    public $categories;

    public int $interval = 5;

    public bool $poll = true;

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

    public function pollData()
    {
        if ($this->poll || $this->interval <= 10) {
            $this->loadData();
            if ($this->interval > 10) {
                $this->poll = false;
            }
        }
    }

    public function loadData()
    {
        try {
            $container = null;
            if ($this->isDockerCompose()) {
                $this->loadContainers();
                if ($this->container === null) {
                    return;
                }
                $container = $this->container;
            }

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
        if ($this->interval <= 10) {
            $this->poll = true;
        }
        $this->loadData();
    }

    public function isRunning(): bool
    {
        if (! $this->isDockerCompose()) {
            return str($this->resource->status)->contains('running');
        }

        if ($this->containersLoaded) {
            return $this->containers !== [];
        }

        return ! str($this->resource->status)->startsWith('exited');
    }

    private function isDockerCompose(): bool
    {
        return $this->resource instanceof Application && $this->resource->build_pack === 'dockercompose';
    }

    private function loadContainers(): void
    {
        $server = $this->resource->destination->server;
        $containers = $server->isFunctional()
            ? getCurrentApplicationContainerStatus($server, $this->resource->id, 0)
            : collect();

        $this->containers = mapComposeContainersToMetricsNames($containers);
        $this->containersLoaded = true;
        if (! array_key_exists($this->container ?? '', $this->containers)) {
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
