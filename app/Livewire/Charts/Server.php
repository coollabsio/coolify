<?php

namespace App\Livewire\Charts;

use App\Models\Server as ModelsServer;
use Livewire\Component;

class Server extends Component
{
    public ModelsServer $server;

    public $chartId = 'server';

    public $data;

    public $categories;

    public function render()
    {
        return view('livewire.charts.server');
    }

    public function mount()
    {
        $this->loadData();
    }

    public function loadData()
    {
        $metrics = $this->server->getMetrics();
        $metrics = collect($metrics)->map(function ($metric) {
            return [$metric[0], $metric[1]];
        });
        $this->dispatch("refreshChartData-{$this->chartId}", [
            'seriesData' => $metrics,
        ]);
    }
}
