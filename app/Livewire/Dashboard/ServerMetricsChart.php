<?php

namespace App\Livewire\Dashboard;

use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ServerMetricsChart extends Component
{
    public Server $server;

    public function loadData(): void
    {
        try {
            $this->dispatch("dashboard-server-metrics-{$this->server->uuid}", [
                'cpu' => $this->server->getCpuMetrics(10),
                'memory' => $this->server->getMemoryMetrics(10),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function render(): View
    {
        return view('livewire.dashboard.server-metrics-chart');
    }
}
