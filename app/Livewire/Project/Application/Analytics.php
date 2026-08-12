<?php

namespace App\Livewire\Project\Application;

use App\Livewire\Concerns\BuildsTrafficChartPayload;
use App\Models\Application;
use App\Services\SentinelTrafficClient;
use Livewire\Component;

class Analytics extends Component
{
    use BuildsTrafficChartPayload;

    public Application $application;

    public string $chartId = 'application-analytics';

    public string $range = '24h';

    public bool $enabled = false;

    // Realtime refresh. Off by default (click "Live" to arm it). Only meaningful on the
    // 24h range; the 60s cadence matches the SentinelTrafficClient cache TTL and
    // Sentinel's per-minute rollups. The control is disabled for 7d/30d.
    public bool $live = false;

    public ?array $overview = null;

    public array $topPaths = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $breakdowns = [];

    public ?string $attribution = null;

    /**
     * Per-bucket status-class time series for the stacked area chart. Empty when this
     * app's Sentinel lacks the series endpoint, which flips the chart to the donut.
     *
     * @var array<int, array{bucket: int, s2xx: int, s3xx: int, s4xx: int, s5xx: int}>
     */
    public array $series = [];

    public bool $hasSeries = false;

    /** @var array<int, string> */
    protected array $breakdownDimensions = ['country', 'referer', 'browser', 'os', 'device', 'protocol', 'cache', 'status', 'agent', 'ip', 'useragent'];

    public function mount(): void
    {
        $this->enabled = (bool) $this->application->destination?->server?->isTrafficAnalyticsEnabled();

        if ($this->enabled) {
            $this->loadData();
        }
    }

    public function setRange(string $range): void
    {
        $this->range = in_array($range, ['24h', '7d', '30d'], true) ? $range : '24h';
        $this->loadData();
    }

    public function toggleLive(): void
    {
        if ($this->range !== '24h') {
            return;
        }
        $this->live = ! $this->live;
    }

    /**
     * Realtime polling is only armed when the user has it on and the range is 24h.
     */
    public function isLivePollable(): bool
    {
        return $this->live && $this->range === '24h';
    }

    public function loadData(): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            [$from, $to] = $this->window();
            $client = $this->trafficClient();
            $key = $this->application->uuid;

            $this->overview = $client->overview($key, $from, $to)->toArray();

            // Every path belongs to this one app, so decorate each row with its domain
            // for a consistent "domain + path" presentation and an openable live link.
            $domain = $this->applicationDomain();
            $this->topPaths = $client->paths($key, $from, $to, 50)
                ->map(fn ($path) => ['domain' => $domain] + $path->toArray())
                ->all();

            $breakdowns = [];
            foreach ($this->breakdownDimensions as $dimension) {
                $breakdowns[$dimension] = $client->breakdown($key, $dimension, $from, $to, 50)
                    ->map(fn ($row) => $row->toArray())
                    ->all();
            }
            $this->breakdowns = $breakdowns;

            $this->attribution = $client->attribution();

            // Per-bucket status series; absent on older Sentinel builds (empty → donut fallback).
            // Isolated so a series hiccup never errors the rest of the widget.
            try {
                $this->series = $client->series($key, $this->range)
                    ->map(fn ($bucket) => $bucket->toArray())
                    ->all();
            } catch (\Throwable $e) {
                $this->series = [];
            }
            $this->hasSeries = $this->series !== [];

            $this->dispatch("refreshChartData-{$this->chartId}-status", $this->chartPayload());
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Payload for the status chart: the stacked-area time series when available,
     * plus the donut totals as a fallback for older Sentinel builds.
     *
     * @return array<string, mixed>
     */
    protected function chartPayload(): array
    {
        $device = $this->deviceChartData();

        return [
            'hasSeries' => $this->hasSeries,
            'range' => $this->range,
            'seriesData' => [
                $this->overview['s2xx'] ?? 0,
                $this->overview['s3xx'] ?? 0,
                $this->overview['s4xx'] ?? 0,
                $this->overview['s5xx'] ?? 0,
            ],
            'timeSeries' => [
                'categories' => array_column($this->series, 'bucket'),
                's2xx' => array_column($this->series, 's2xx'),
                's3xx' => array_column($this->series, 's3xx'),
                's4xx' => array_column($this->series, 's4xx'),
                's5xx' => array_column($this->series, 's5xx'),
            ],
            'requestsSpark' => $this->requestsSpark(),
            'errorsSpark' => $this->errorsSpark(),
            'bandwidthSpark' => $this->bandwidthSpark(),
            'uniquesSpark' => $this->uniquesSpark(),
            'geo' => $this->geoMarkers(),
            'deviceLabels' => $device['labels'],
            'deviceSeries' => $device['series'],
        ];
    }

    public function errorRate(): float
    {
        if (! $this->overview || (int) ($this->overview['requests'] ?? 0) === 0) {
            return 0.0;
        }

        $errors = (int) ($this->overview['s4xx'] ?? 0) + (int) ($this->overview['s5xx'] ?? 0);

        return round(($errors / $this->overview['requests']) * 100, 2);
    }

    public function bandwidthBytes(): int
    {
        if (! $this->overview) {
            return 0;
        }

        return (int) ($this->overview['bytesIn'] ?? 0) + (int) ($this->overview['bytesOut'] ?? 0);
    }

    protected function trafficClient(): SentinelTrafficClient
    {
        return app(SentinelTrafficClient::class, ['server' => $this->application->destination->server]);
    }

    /**
     * Primary domain host for this application (first configured FQDN), or null when
     * none is set — used to present paths as "domain + path" with an openable link.
     */
    protected function applicationDomain(): ?string
    {
        $first = collect($this->application->fqdns)->first();

        return $first ? (parse_url($first, PHP_URL_HOST) ?: null) : null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function window(): array
    {
        $to = now();
        $from = match ($this->range) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDay(),
        };

        return [$from->toIso8601ZuluString(), $to->toIso8601ZuluString()];
    }

    public function render()
    {
        return view('livewire.project.application.analytics');
    }
}
