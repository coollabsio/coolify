<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Server;
use App\Services\SentinelMetricsClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetServerMetrics extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    private const DEFAULT_MINUTES = 60;

    private const MAX_MINUTES = 43200;

    protected string $name = 'get_server_metrics';

    protected string $description = 'Get current and recent Sentinel metrics (CPU %, memory bytes, root disk %, load, network bytes/s) for one server of the authenticated team. Metrics must be enabled on the server. Requires read ability.';

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $uuid = $request->get('server_uuid');
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'server_uuid argument is required.');
        }

        $minutes = filter_var($request->get('minutes', self::DEFAULT_MINUTES), FILTER_VALIDATE_INT);
        if ($minutes === false || $minutes < 1) {
            return $this->mcpError($request, 'minutes must be a positive integer.');
        }
        $minutes = min($minutes, self::MAX_MINUTES);

        $server = Server::whereTeamId($teamId)->where('uuid', $uuid)->with('settings')->first();
        if (! $server) {
            return $this->mcpError($request, "Server [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        if (! $server->isMetricsEnabled()) {
            return $this->mcpError($request, 'Metrics are not enabled for this server. Enable Sentinel metrics in the server settings first.', ['resource_uuid' => $uuid]);
        }

        /** @var SentinelMetricsClient $client */
        $client = app(SentinelMetricsClient::class, ['server' => $server]);
        $range = match (true) {
            $minutes <= 1440 => '24h',
            $minutes <= 10080 => '7d',
            default => '30d',
        };
        $sinceMs = now()->subMinutes($minutes)->getTimestampMs();

        $summary = $this->safely(fn () => $client->summary());
        $network = $this->safely(fn () => $client->networkHistory($range)) ?? ['rx' => [], 'tx' => []];

        $series = [
            'cpu_percent' => $this->recent($this->safely(fn () => $client->history('cpu', $range)), $sinceMs),
            'memory_used_bytes' => $this->recent($this->safely(fn () => $client->history('memory', $range)), $sinceMs),
            'disk_used_percent' => $this->recent($this->safely(fn () => $client->history('disk', $range)), $sinceMs),
            'load1' => $this->recent($this->safely(fn () => $client->history('load', $range)), $sinceMs),
            'network_rx_bytes_per_sec' => $this->recent($network['rx'] ?? [], $sinceMs),
            'network_tx_bytes_per_sec' => $this->recent($network['tx'] ?? [], $sinceMs),
        ];

        $data = [
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'online' => $summary !== null,
            'minutes' => $minutes,
            'bucket_seconds' => intdiv(SentinelMetricsClient::bucketMs($range), 1000),
            'current' => $summary === null ? null : [
                'cpu_percent' => $summary['cpu'] ?? null,
                'memory_used_bytes' => $summary['memUsed'] ?? null,
                'memory_total_bytes' => $summary['memTotal'] ?? null,
                'disk_used_bytes' => $summary['diskUsed'] ?? null,
                'disk_total_bytes' => $summary['diskTotal'] ?? null,
                'disk_used_percent' => $summary['diskPercent'] ?? null,
                'load1' => $summary['load1'] ?? null,
                'network_rx_bytes_per_sec' => $summary['netRx'] ?? null,
                'network_tx_bytes_per_sec' => $summary['netTx'] ?? null,
            ],
            'series' => $series,
            'series_format' => '[timestamp_ms, value] pairs, averaged per bucket',
        ];

        return $this->mcpSuccess($request, $this->respond($data), ['resource_uuid' => $uuid, 'minutes' => $minutes]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'server_uuid' => $schema->string()->description('Server UUID.')->required(),
            'minutes' => $schema->integer()->description('How many minutes of history to return (default 60, max 43200 = 30 days).'),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array{0: int, 1: float}>|null  $points
     * @return array<int, array{0: int, 1: float}>
     */
    private function recent(?array $points, int $sinceMs): array
    {
        return array_values(array_filter($points ?? [], fn ($point): bool => is_array($point) && (int) ($point[0] ?? 0) >= $sinceMs));
    }
}
