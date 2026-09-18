<?php

namespace App\Livewire\Server;

use App\Actions\Server\StartSentinel;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
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

    #[Validate(['required', 'integer', 'min:1'])]
    public int|string $sentinelMetricsRefreshRateSeconds;

    #[Validate(['required', 'integer', 'min:1'])]
    public int|string $sentinelMetricsHistoryDays;

    #[Validate(['required', 'integer', 'min:10'])]
    public int|string $sentinelPushIntervalSeconds;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->sentinelMetricsRefreshRateSeconds = $this->server->settings->sentinel_metrics_refresh_rate_seconds;
            $this->sentinelMetricsHistoryDays = $this->server->settings->sentinel_metrics_history_days;
            $this->sentinelPushIntervalSeconds = $this->server->settings->sentinel_push_interval_seconds;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveMetricsSettings(): void
    {
        try {
            $this->authorize('update', $this->server);
            $this->validate();

            $this->server->settings->sentinel_metrics_refresh_rate_seconds = $this->sentinelMetricsRefreshRateSeconds;
            $this->server->settings->sentinel_metrics_history_days = $this->sentinelMetricsHistoryDays;
            $this->server->settings->sentinel_push_interval_seconds = $this->sentinelPushIntervalSeconds;
            $this->server->settings->save();

            $this->dispatch('success', 'Metrics settings updated. Restarting Sentinel.');
        } catch (\Throwable $e) {
            handleError($e, $this);
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
            // Disk/network/load arrived in a later Sentinel; fetch each optionally so an
            // older Sentinel (which 404s them) still shows CPU and memory.
            $diskMetrics = $this->optionalMetric(fn () => $this->server->getDiskMetrics($this->interval));
            $loadMetrics = $this->optionalMetric(fn () => $this->server->getLoadMetrics($this->interval));
            $networkMetrics = $this->optionalMetric(fn () => $this->server->getNetworkMetrics($this->interval)) ?? [];
            $this->dispatch("refreshChartData-{$this->chartId}-metrics", [
                'cpuSeries' => $cpuMetrics,
                'memorySeries' => $memoryMetrics,
                'diskSeries' => $diskMetrics,
                'loadSeries' => $loadMetrics,
                'networkRxSeries' => $networkMetrics['rx'] ?? [],
                'networkTxSeries' => $networkMetrics['tx'] ?? [],
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Fetch a metric that may not exist on an older Sentinel; a failure yields null
     * rather than breaking the whole chart refresh.
     */
    private function optionalMetric(callable $fetch): mixed
    {
        try {
            return $fetch();
        } catch (\Throwable) {
            return null;
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
