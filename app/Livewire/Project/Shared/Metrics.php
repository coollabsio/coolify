<?php

namespace App\Livewire\Project\Shared;

use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class Metrics extends Component
{
    public $resource;

    public $chartId = 'metrics';

    public $data;

    public $categories;

    public int $interval = 5;

    public bool $live = true;

    public function loadData()
    {
        try {
            $this->resource->prefetchMetrics(['cpu', 'memory'], $this->interval);
            $cpuMetrics = $this->resource->getCpuMetrics($this->interval);
            $memoryMetrics = $this->resource->getMemoryMetrics($this->interval);
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
        if (! $this->live) {
            return null;
        }

        return $this->interval <= 60 ? 5 : 60;
    }

    public function render()
    {
        return view('livewire.project.shared.metrics');
    }
}
