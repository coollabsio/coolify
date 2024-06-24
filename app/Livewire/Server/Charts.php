<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Livewire\Component;

class Charts extends Component
{
    public Server $server;

    public $chartId = 'server';

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
            $cpuMetrics = $this->server->getCpuMetrics($this->interval);
            $memoryMetrics = $this->server->getMemoryMetrics($this->interval);
            $cpuMetrics = collect($cpuMetrics)->map(function ($metric) {
                return [$metric[0], $metric[1]];
            });
            $memoryMetrics = collect($memoryMetrics)->map(function ($metric) {
                return [$metric[0], $metric[1]];
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
}
