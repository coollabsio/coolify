<?php

namespace App\Livewire\Server;

use App\Actions\Server\StartSentinel;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Charts extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public $chartId = 'server';

    public $data;

    public $categories;

    public int $interval = 5;

    public bool $poll = true;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function toggleMetrics(): void
    {
        try {
            $this->authorize('update', $this->server);
            $this->server->settings->is_metrics_enabled = ! $this->server->settings->is_metrics_enabled;
            $this->server->settings->save();
            $this->server->refresh();

            if ($this->server->isMetricsEnabled()) {
                StartSentinel::run($this->server, true);
                $this->dispatch('success', 'Metrics enabled. Starting Sentinel.');
                $this->dispatch('refreshServerShow');
                $this->redirect(route('server.metrics', ['server_uuid' => $this->server->uuid]), navigate: true);
            } else {
                $this->server->restartSentinel();
                $this->dispatch('success', 'Metrics disabled. Restarting Sentinel.');
                $this->dispatch('refreshServerShow');
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
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
            $cpuMetrics = $this->server->getCpuMetrics($this->interval);
            $memoryMetrics = $this->server->getMemoryMetrics($this->interval);
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
