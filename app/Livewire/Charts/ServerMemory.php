<?php

namespace App\Livewire\Charts;

use App\Models\Server;
use Livewire\Component;

class ServerMemory extends Component
{
    public Server $server;

    public $chartId = 'server-memory';

    public $data;

    public $categories;
    public $interval = 5;

    public function render()
    {
        return view('livewire.charts.server-memory');
    }
    public function mount()
    {
        $this->loadData();
    }

    public function loadData()
    {
        try {
            $metrics = $this->server->getMemoryMetrics($this->interval);
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
    public function setInterval() {
        $this->loadData();
    }
}
