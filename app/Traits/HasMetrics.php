<?php

namespace App\Traits;

use App\Models\Server;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

trait HasMetrics
{
    /**
     * Raw Sentinel responses from prefetchMetrics(), keyed by "type:mins"; each is read once.
     *
     * @var array<string, string>
     */
    protected array $prefetchedMetricResponses = [];

    /**
     * Fetch several metric types in one SSH round-trip instead of one per type. The
     * get*Metrics() getters then read these responses. On a failure nothing is stored,
     * so each getter fetches on its own as before.
     *
     * @param  array<int, string>  $types
     */
    public function prefetchMetrics(array $types, int $mins): void
    {
        $server = $this->getMetricsServer();
        if (! $server->isMetricsEnabled()) {
            return;
        }

        $from = now()->subMinutes($mins)->toIso8601ZuluString();
        $token = $this->metricsToken($server);
        // The token reaches curl on stdin (`-H @-`), so it is not a process argument. The token
        // only has letters, digits and `._-+/=` (ServerSetting::isValidSentinelToken), so it
        // cannot end the heredoc. Responses are separated by a 0x1E record separator.
        $script = implode("\nprintf '\\036'\n", array_map(
            fn (string $type) => 'curl -s -H @- "'.$this->getMetricsEndpoint($type, $from)."\" <<'COOLIFY_SENTINEL_AUTH'\nAuthorization: Bearer {$token}\nCOOLIFY_SENTINEL_AUTH",
            $types
        ));

        try {
            $output = instant_remote_process(
                ["docker exec -i coolify-sentinel sh -s <<'COOLIFY_SENTINEL_SCRIPT'\n{$script}\nCOOLIFY_SENTINEL_SCRIPT"],
                $server,
                maxAttempts: 1
            );
        } catch (\Throwable) {
            return;
        }

        $responses = explode("\x1e", (string) $output);
        if (count($responses) !== count($types)) {
            return;
        }

        foreach ($types as $index => $type) {
            $this->prefetchedMetricResponses["{$type}:{$mins}"] = trim($responses[$index]);
        }
    }

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

        if (array_any($rows, fn ($row) => array_key_exists('mount', $row))) {
            $rows = array_values(array_filter($rows, fn ($row) => ($row['mount'] ?? null) === '/'));
        }

        return $this->mapRows($rows, 'usedPercent', $mins);
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

        $key = "{$type}:{$mins}";
        if (array_key_exists($key, $this->prefetchedMetricResponses)) {
            $response = $this->prefetchedMetricResponses[$key];
            unset($this->prefetchedMetricResponses[$key]);
        } else {
            $from = now()->subMinutes($mins)->toIso8601ZuluString();
            $endpoint = $this->getMetricsEndpoint($type, $from);
            $token = $this->metricsToken($server);

            $response = instant_remote_process(
                ["docker exec coolify-sentinel sh -c 'curl -H \"Authorization: Bearer {$token}\" {$endpoint}'"],
                $server,
                false
            );
        }

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
     * Sentinel keeps raw samples for the last 24 to 48 hours and one-minute averages
     * before that. LTTB buckets by point count, so on ranges that reach past the raw
     * window the raw part got most of the chart points. Those ranges are averaged per
     * minute first, the same as Sentinel does, so the density is even over time.
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

        if ($mins > 24 * 60) {
            $metrics = $this->averagePerMinute($metrics);
        }

        if ($mins > 60 && count($metrics) > 1000) {
            $metrics = downsampleLTTB($metrics, 1000);
        }

        return $metrics;
    }

    /**
     * Average [timestampMs, value] pairs into one-minute buckets, timestamped at the
     * bucket start like Sentinel's own downsampling. Samples that are a minute or more
     * apart keep their own value.
     *
     * @param  array<int, array{0: int, 1: float}>  $metrics
     * @return array<int, array{0: int, 1: float}>
     */
    private function averagePerMinute(array $metrics): array
    {
        $sums = [];
        $counts = [];
        foreach ($metrics as [$time, $value]) {
            $bucket = intdiv($time, 60_000) * 60_000;
            $sums[$bucket] = ($sums[$bucket] ?? 0.0) + $value;
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
        }
        ksort($sums);

        return array_map(fn (int $bucket) => [$bucket, $sums[$bucket] / $counts[$bucket]], array_keys($sums));
    }

    private function metricsToken(Server $server): string
    {
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

        return $token;
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
