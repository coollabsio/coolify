<?php

namespace App\Livewire\Node;

use App\Actions\Node\ConfigureSentinelMetrics;
use App\Models\Node;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Component;

class Metrics extends Component
{
    use AuthorizesRequests;

    public Node $node;

    public string $chartId = 'node';

    public int $interval = 5;

    public bool $live = true;

    public function mount(string $node_uuid): void
    {
        abort_unless(nodesEnabled(), 404);
        $this->node = Node::query()->where('team_id', currentTeam()->id)->where('uuid', $node_uuid)->firstOrFail();
        $this->authorize('view', $this->node);
    }

    public function toggleMetrics(): void
    {
        $this->authorize('update', $this->node);
        try {
            $this->node->refresh();
            $enabled = ! $this->node->isMetricsEnabled();
            ConfigureSentinelMetrics::run($this->node, $enabled);
            $this->node->refresh();
            $settings = $this->node->settings ?? [];
            $settings['is_metrics_enabled'] = $enabled;
            $this->node->update(['settings' => $settings]);
            $this->dispatch('success', $enabled ? 'Metrics enabled. Starting Sentinel.' : 'Metrics disabled. Restarting Sentinel.');
            $this->redirect(route('node.metrics', ['node_uuid' => $this->node->uuid]), navigate: true);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function loadData()
    {
        $this->authorize('view', $this->node);
        $this->node->refresh();
        if (! $this->node->isMetricsEnabled()) {
            return;
        }
        $this->validate(['interval' => 'required|integer|in:5,10,30,60,720,10080,43200']);
        try {
            $this->node->prefetchMetrics(['cpu', 'memory', 'disk', 'load', 'network'], $this->interval);
            $cpuMetrics = $this->node->getCpuMetrics($this->interval);
            $memoryMetrics = $this->node->getMemoryMetrics($this->interval);
            // Disk/network/load arrived in a later Sentinel; fetch each optionally so an
            // older Sentinel (which 404s them) still shows CPU and memory.
            $diskMetrics = $this->optionalMetric(fn () => $this->node->getDiskMetrics($this->interval));
            $loadMetrics = $this->optionalMetric(fn () => $this->node->getLoadMetrics($this->interval));
            $networkMetrics = $this->optionalMetric(fn () => $this->node->getNetworkMetrics($this->interval)) ?? [];
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
        $this->loadData();
    }

    /**
     * Ranges up to one hour refresh live by default. Longer ranges load once, because each
     * refresh fetches the full range from Sentinel over SSH; the user can still turn live on.
     */
    public function updatedInterval(): void
    {
        $this->live = $this->interval <= 60;
    }

    public function updatedLive(): void
    {
        if ($this->live) {
            $this->pollData();
        }
    }

    /**
     * The poll target. The server decides the refresh rate, so a client cannot fetch faster
     * than the range allows or while live refresh is off.
     */
    public function pollData(): void
    {
        $seconds = $this->pollSeconds();
        if ($seconds === null) {
            return;
        }

        // One second of slack, so a poll that fires slightly early is not dropped.
        if (RateLimiter::attempt('metrics-poll:'.$this->getId(), 1, fn () => true, $seconds - 1) === false) {
            return;
        }

        $this->loadData();
    }

    /**
     * Seconds between live refreshes, or null when live refresh is off. Short ranges refresh
     * every 5 seconds; longer ranges change slowly, so they refresh every minute.
     */
    public function pollSeconds(): ?int
    {
        if (! $this->live || ! $this->node->isMetricsEnabled()) {
            return null;
        }

        return $this->interval <= 60 ? 5 : 60;
    }

    public function render(): View
    {
        return view('livewire.node.metrics');
    }
}
