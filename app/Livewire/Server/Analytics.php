<?php

namespace App\Livewire\Server;

use App\Models\Application;
use App\Models\Server;
use App\Services\SentinelTrafficClient;
use Livewire\Component;

class Analytics extends Component
{
    public Server $server;

    public string $chartId = 'server-analytics';

    public string $range = '24h';

    public bool $enabled = false;

    public ?array $overview = null;

    public array $topPaths = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $breakdowns = [];

    /** @var array<int, array<string, mixed>> */
    public array $leaderboard = [];

    public ?string $attribution = null;

    /** @var array<int, string> */
    protected array $breakdownDimensions = ['country', 'referer', 'browser', 'os', 'device'];

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        $this->enabled = (bool) $this->server->isTrafficAnalyticsEnabled();

        if ($this->enabled) {
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
        if (! $this->enabled) {
            return;
        }

        try {
            [$from, $to] = $this->window();
            $client = $this->trafficClient();

            $this->overview = $client->overview(null, $from, $to)->toArray();
            $this->topPaths = $client->paths(null, $from, $to, 20)
                ->map(fn ($path) => $path->toArray())
                ->all();

            $breakdowns = [];
            foreach ($this->breakdownDimensions as $dimension) {
                $breakdowns[$dimension] = $client->breakdown(null, $dimension, $from, $to, 10)
                    ->map(fn ($row) => $row->toArray())
                    ->all();
            }
            $this->breakdowns = $breakdowns;

            $this->attribution = $client->attribution();

            $this->leaderboard = $this->loadLeaderboard($client, $from, $to);

            $this->dispatch("refreshChartData-{$this->chartId}-status", [
                'seriesData' => [
                    $this->overview['s2xx'] ?? 0,
                    $this->overview['s3xx'] ?? 0,
                    $this->overview['s4xx'] ?? 0,
                    $this->overview['s5xx'] ?? 0,
                ],
            ]);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadLeaderboard(SentinelTrafficClient $client, string $from, string $to): array
    {
        $rows = [];

        foreach ($client->apps() as $uuid) {
            if (! is_string($uuid) || $uuid === '') {
                continue;
            }

            $overview = $client->overview($uuid, $from, $to)->toArray();

            $rows[] = [
                'uuid' => $uuid,
                'name' => Application::whereUuid($uuid)->first()?->name ?? $uuid,
                'requests' => (int) ($overview['requests'] ?? 0),
                'bandwidth' => (int) ($overview['bytesIn'] ?? 0) + (int) ($overview['bytesOut'] ?? 0),
            ];
        }

        usort($rows, fn ($a, $b) => $b['requests'] <=> $a['requests']);

        return array_slice($rows, 0, 10);
    }

    protected function trafficClient(): SentinelTrafficClient
    {
        return app(SentinelTrafficClient::class, ['server' => $this->server]);
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
        return view('livewire.server.analytics');
    }
}
