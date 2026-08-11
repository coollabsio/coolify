<?php

namespace App\Livewire\Dashboard;

use App\Models\Application;
use App\Models\Server;
use App\Services\SentinelTrafficClient;
use App\Services\TrafficAnalyticsAggregator;
use Illuminate\Support\Collection;
use Livewire\Component;

class TrafficAnalytics extends Component
{
    public Collection $servers;

    public string $range = '24h';

    public ?array $overview = null;

    public bool $latencyApproximate = false;

    public bool $uniquesApproximate = false;

    /** @var array<int, array<string, mixed>> */
    public array $topApps = [];

    /** @var array<int, array<string, mixed>> */
    public array $topCountries = [];

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
        $appRows = [];
        $countryTotals = [];

        foreach ($this->servers as $server) {
            try {
                $client = $this->trafficClient($server);

                $overviews[] = $client->overview(null, $from, $to);

                foreach ($client->apps() as $uuid) {
                    if (! is_string($uuid) || $uuid === '') {
                        continue;
                    }

                    $appOverview = $client->overview($uuid, $from, $to)->toArray();

                    $appRows[] = [
                        'uuid' => $uuid,
                        'name' => Application::ownedByCurrentTeam()->whereUuid($uuid)->first()?->name ?? $uuid,
                        'requests' => (int) ($appOverview['requests'] ?? 0),
                        'bandwidth' => (int) ($appOverview['bytesIn'] ?? 0) + (int) ($appOverview['bytesOut'] ?? 0),
                    ];
                }

                foreach ($client->breakdown(null, 'country', $from, $to, 20) as $row) {
                    $data = $row->toArray();
                    $value = $data['value'] !== '' ? $data['value'] : 'Unknown';

                    $countryTotals[$value] ??= ['value' => $value, 'requests' => 0, 'bytesOut' => 0];
                    $countryTotals[$value]['requests'] += (int) ($data['requests'] ?? 0);
                    $countryTotals[$value]['bytesOut'] += (int) ($data['bytesOut'] ?? 0);
                }
            } catch (\Throwable $e) {
                // Skip unreachable/failed servers so one bad server doesn't break the whole summary.
                continue;
            }
        }

        if (empty($overviews)) {
            // Every server's fetch failed; don't present an all-zero KPI panel as if it were real data.
            $this->overview = null;
            $this->latencyApproximate = false;
            $this->uniquesApproximate = false;
            $this->topApps = [];
            $this->topCountries = [];

            return;
        }

        $result = TrafficAnalyticsAggregator::sumOverviews($overviews);

        $this->overview = $result['overview']->toArray();
        $this->latencyApproximate = $result['latencyApproximate'];
        $this->uniquesApproximate = $result['uniquesApproximate'];

        usort($appRows, fn ($a, $b) => $b['requests'] <=> $a['requests']);
        $this->topApps = array_slice($appRows, 0, 10);

        $countries = array_values($countryTotals);
        usort($countries, fn ($a, $b) => $b['requests'] <=> $a['requests']);
        $this->topCountries = array_slice($countries, 0, 10);
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
