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

    public int $interval = 5;

    public bool $poll = true;

    public function render()
    {
        return view('livewire.charts.server-memory');
    }

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

    public function setInterval()
    {
        if ($this->interval <= 10) {
            $this->poll = true;
        }
        $this->loadData();
    }
}
