<?php

namespace App\Livewire\Charts;

use App\Models\Server as ModelsServer;
use Livewire\Component;

class ServerCpu extends Component
{
    public ModelsServer $server;

    public $chartId = 'server-cpu';

    public $data;

    public $categories;

    public $interval = 5;

    public function render()
    {
        return view('livewire.charts.server-cpu');
    }

    public function mount()
    {
        $this->loadData();
    }

    public function loadData()
    {
        try {
            $metrics = $this->server->getCpuMetrics($this->interval);
            $metrics = collect($metrics)->map(function ($metric) {
                return [$metric[0], $metric[1]];
            });
            $this->dispatch("refreshChartData-{$this->chartId}", [
                'seriesData' => $metrics,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function setInterval()
    {
        $this->loadData();
    }
}
