<?php

namespace App\Livewire\Project\Shared;

use Livewire\Component;

class Metrics extends Component
{
    public $resource;

    public $chartId = 'metrics';

    public $data;

    public $categories;

    public int $interval = 5;

    public bool $live = true;

    public function loadData()
    {
        try {
            $this->resource->prefetchMetrics(['cpu', 'memory'], $this->interval);
            $cpuMetrics = $this->resource->getCpuMetrics($this->interval);
            $memoryMetrics = $this->resource->getMemoryMetrics($this->interval);
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

    public function updatedLive(): void
    {
        if ($this->live) {
            $this->loadData();
        }
    }

    /**
     * Short ranges refresh every 5 seconds; longer ranges change slowly, so they refresh every minute.
     */
    public function pollSeconds(): int
    {
        return $this->interval <= 60 ? 5 : 60;
    }

    public function render()
    {
        return view('livewire.project.shared.metrics');
    }
}
