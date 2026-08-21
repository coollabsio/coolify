<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\BuildsTrafficChartPayload;
use App\Models\Server;
use App\Services\SentinelTrafficClient;
use App\Services\TrafficAnalyticsAggregator;
use Illuminate\Support\Collection;
use Livewire\Component;

class TrafficAnalytics extends Component
{
    use BuildsTrafficChartPayload;

    public string $chartId = 'dashboard-traffic';

    public Collection $servers;

    public string $range = '24h';

    public ?array $overview = null;

    public bool $latencyApproximate = false;

    public bool $uniquesApproximate = false;

    /**
     * Per-bucket status-class series, summed across servers; feeds the KPI sparklines.
     *
     * @var array<int, array{bucket: int, s2xx: int, s3xx: int, s4xx: int, s5xx: int}>
     */
    public array $series = [];

    public function mount(): void
    {
        $this->servers = Server::ownedByCurrentTeamCached()
            ->filter(fn (Server $server) => $server->isTrafficAnalyticsEnabled())
            ->values();

        if ($this->servers->isNotEmpty()) {
            $this->loadData();
        }
    }

    public function setRange(string $range): void
    {
        $this->range = in_array($range, ['24h', '7d', '30d'], true) ? $range : '24h';
        $this->loadData();
    }

    public function loadData(): void
    {
        if ($this->servers->isEmpty()) {
            return;
        }

        [$from, $to] = $this->window();

        $overviews = [];
        $seriesByBucket = [];

        foreach ($this->servers as $server) {
            try {
                $client = $this->trafficClient($server);

                $overviews[] = $client->overview(null, $from, $to);

                // Per-bucket status series, summed across servers, for the sparklines.
                // Isolated so a series hiccup (older Sentinel) never drops a server's overview.
                try {
                    foreach ($client->series(null, $this->range) as $bucket) {
                        $data = $bucket->toArray();
                        $ts = (int) ($data['bucket'] ?? 0);

                        $seriesByBucket[$ts] ??= ['bucket' => $ts, 's2xx' => 0, 's3xx' => 0, 's4xx' => 0, 's5xx' => 0, 'requests' => 0, 'bytesIn' => 0, 'bytesOut' => 0, 'uniqueVisitors' => 0, 'p95' => 0.0];
                        $seriesByBucket[$ts]['s2xx'] += (int) ($data['s2xx'] ?? 0);
                        $seriesByBucket[$ts]['s3xx'] += (int) ($data['s3xx'] ?? 0);
                        $seriesByBucket[$ts]['s4xx'] += (int) ($data['s4xx'] ?? 0);
                        $seriesByBucket[$ts]['s5xx'] += (int) ($data['s5xx'] ?? 0);
                        $seriesByBucket[$ts]['requests'] += (int) ($data['requests'] ?? 0);
                        $seriesByBucket[$ts]['bytesIn'] += (int) ($data['bytesIn'] ?? 0);
                        $seriesByBucket[$ts]['bytesOut'] += (int) ($data['bytesOut'] ?? 0);
                        $seriesByBucket[$ts]['uniqueVisitors'] += (int) ($data['uniqueVisitors'] ?? 0);
                        $seriesByBucket[$ts]['p95'] = max($seriesByBucket[$ts]['p95'], (float) ($data['p95'] ?? 0));
                    }
                } catch (\Throwable $e) {
                    // Leave this server out of the sparkline series.
                    \Log::debug('Traffic series fetch failed', ['server' => $server->uuid, 'error' => $e->getMessage()]);
                }
            } catch (\Throwable $e) {
                // Skip unreachable/failed servers so one bad server doesn't break the whole summary.
                \Log::debug('Traffic overview fetch failed', ['server' => $server->uuid, 'error' => $e->getMessage()]);

                continue;
            }
        }

        if (empty($overviews)) {
            // Every server's fetch failed; don't present an all-zero KPI panel as if it were real data.
            $this->overview = null;
            $this->latencyApproximate = false;
            $this->uniquesApproximate = false;
            $this->series = [];

            return;
        }

        $result = TrafficAnalyticsAggregator::sumOverviews($overviews);

        $this->overview = $result['overview']->toArray();
        $this->latencyApproximate = $result['latencyApproximate'];
        $this->uniquesApproximate = $result['uniquesApproximate'];

        ksort($seriesByBucket);
        $this->series = array_values($seriesByBucket);

        $this->dispatch("refreshChartData-{$this->chartId}-status", [
            'requestsSpark' => $this->requestsSpark(),
            'errorsSpark' => $this->errorsSpark(),
            'bandwidthSpark' => $this->bandwidthSpark(),
            'uniquesSpark' => $this->uniquesSpark(),
        ]);
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

    protected function trafficClient(Server $server): SentinelTrafficClient
    {
        return app(SentinelTrafficClient::class, ['server' => $server]);
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
        return view('livewire.dashboard.traffic-analytics');
    }
}
