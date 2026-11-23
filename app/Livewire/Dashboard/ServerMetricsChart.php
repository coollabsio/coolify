<?php

namespace App\Livewire\Dashboard;

use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Isolate;
use Livewire\Component;

/**
 * Each chart sends its own request, so one slow server does not delay the charts of the other servers.
 */
#[Isolate]
class ServerMetricsChart extends Component
{
    public Server $server;

    public function loadData(): void
    {
        if (! $this->server->isFunctional()) {
            return;
        }

        try {
            $this->server->prefetchMetrics(['cpu', 'memory'], 10);
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
