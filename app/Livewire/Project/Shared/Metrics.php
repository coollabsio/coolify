<?php

namespace App\Livewire\Project\Shared;

use Livewire\Component;

class Metrics extends Component
{
    public $resource;

    public $chartId = 'container-cpu';

    public $data;

    public $categories;

    public int $interval = 5;

    public bool $poll = true;

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
            $metrics = $this->resource->getMetrics($this->interval);
            $cpuMetrics = collect($metrics)->map(function ($metric) {
                return [$metric[0], $metric[1]];
            });
            $memoryMetrics = collect($metrics)->map(function ($metric) {
                return [$metric[0], $metric[2]];
            });
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

    public function render()
    {
        return view('livewire.project.shared.metrics');
    }
}
