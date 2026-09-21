<?php

namespace App\Traits;

use App\Models\Server;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

trait HasMetrics
{
    public function getCpuMetrics(int $mins = 5): ?array
    {
        return $this->getMetrics('cpu', $mins, 'percent');
    }

    public function getMemoryMetrics(int $mins = 5): ?array
    {
        if ($this->isServerMetrics()) {
            return $this->getMetrics('memory', $mins, 'usedPercent');
        }

        $metrics = $this->getMetrics('memory', $mins, 'used');
        if ($metrics === null) {
            return null;
        }

        return convertContainerMemoryBytesToMegabytes($metrics);
    }

    /**
     * Root filesystem usage percentage over time. Disk history interleaves every
     * mount, so we keep only the root mount before mapping.
     */
    public function getDiskMetrics(int $mins = 5): ?array
    {
        $rows = $this->fetchMetricRows('disk', $mins);
        if ($rows === null) {
            return null;
        }

        $root = collect($rows)->filter(fn ($r) => ($r['mount'] ?? null) === '/')->values()->all();

        return $this->mapRows($root ?: $rows, 'usedPercent', $mins);
    }

    /**
     * Host load average (1 minute) over time.
     */
    public function getLoadMetrics(int $mins = 5): ?array
    {
        return $this->getMetrics('load', $mins, 'load1');
    }

    /**
     * Network throughput over time, as separate receive/transmit byte-rate series.
     *
     * @return array{rx: ?array, tx: ?array}|null
     */
    public function getNetworkMetrics(int $mins = 5): ?array
    {
        $rows = $this->fetchMetricRows('network', $mins);
        if ($rows === null) {
            return null;
        }

        return [
            'rx' => $this->mapRows($rows, 'rxBytesPerSec', $mins),
            'tx' => $this->mapRows($rows, 'txBytesPerSec', $mins),
        ];
    }

    private function getMetrics(string $type, int $mins, string $valueField): ?array
    {
        return $this->mapRows($this->fetchMetricRows($type, $mins), $valueField, $mins);
    }

    /**
     * Fetch and decode the raw Sentinel history rows for a metric type, or null when
     * metrics are disabled. Throws on a Sentinel error response (caller decides whether
     * to treat that as fatal or optional).
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function fetchMetricRows(string $type, int $mins): ?array
    {
        $server = $this->getMetricsServer();
        if (! $server->isMetricsEnabled()) {
            return null;
        }

        $from = now()->subMinutes($mins)->toIso8601ZuluString();
        $endpoint = $this->getMetricsEndpoint($type, $from);

        $previousToken = null;
        try {
            $previousToken = $server->settings->sentinel_token;
        } catch (DecryptException) {
            // fall through to ensureValidSentinelToken which will regenerate
        }
        $token = $server->settings->ensureValidSentinelToken();
        if ($token !== $previousToken) {
            Log::warning('Regenerated sentinel token during metrics read; sentinel container restart required', ['server_id' => $server->id]);
        }

        $response = instant_remote_process(
            ["docker exec coolify-sentinel sh -c 'curl -H \"Authorization: Bearer {$token}\" {$endpoint}'"],
            $server,
            false
        );

        if (str($response)->contains('error')) {
            $error = json_decode($response, true);
            $error = data_get($error, 'error', 'Something is not okay, are you okay?');
            if ($error === 'Unauthorized') {
                $error = 'Unauthorized, please check your metrics token or restart Sentinel to set a new token.';
            }
            throw new \Exception($error);
        }

        return json_decode($response, true) ?: [];
    }

    /**
     * Map raw rows to [timestampMs, value] pairs, downsampling long ranges.
     *
     * @param  array<int, array<string, mixed>>|null  $rows
     */
    private function mapRows(?array $rows, string $valueField, int $mins): ?array
    {
        if ($rows === null) {
            return null;
        }

        $metrics = collect($rows)->map(function ($metric) use ($valueField) {
            return [(int) $metric['time'], (float) ($metric[$valueField] ?? 0.0)];
        })->toArray();

        if ($mins > 60 && count($metrics) > 1000) {
            $metrics = downsampleLTTB($metrics, 1000);
        }

        return $metrics;
    }

    private function isServerMetrics(): bool
    {
        return $this instanceof Server;
    }

    private function getMetricsServer(): Server
    {
        return $this->isServerMetrics() ? $this : $this->destination->server;
    }

    private function getMetricsEndpoint(string $type, string $from): string
    {
        $base = 'http://localhost:8888/api';
        if ($this->isServerMetrics()) {
            return "{$base}/{$type}/history?from={$from}";
        }

        return "{$base}/container/{$this->uuid}/{$type}/history?from={$from}";
    }
}
