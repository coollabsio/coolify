<?php

namespace App\Traits;

use App\Models\Server;
use App\Services\SentinelMetricsClient;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

trait HasMetrics
{
    /**
     * Server metric types of getServerMetrics() and the Sentinel row fields they read. `field`
     * gives one series, `fields` one series per output key, and `groupBy` one series per value
     * of that row field.
     *
     * @var array<string, array{field?: string, fields?: array<string, string>, groupBy?: string}>
     */
    public const SERVER_METRIC_TYPES = [
        'cpu' => ['field' => 'percent'],
        'memory' => ['field' => 'usedPercent'],
        'disk' => ['field' => 'usedPercent', 'groupBy' => 'mount'],
        'network' => ['fields' => ['rx' => 'rxBytesPerSec', 'tx' => 'txBytesPerSec']],
        'load' => ['fields' => ['load1' => 'load1', 'load5' => 'load5', 'load15' => 'load15']],
    ];

    /**
     * Raw Sentinel responses from prefetchMetrics(), keyed by "type:mins"; each is read once.
     *
     * @var array<string, string|null>
     */
    protected array $prefetchedMetricResponses = [];

    /**
     * Fetch several metric types in one SSH round-trip instead of one per type. The
     * get*Metrics() getters then read these responses. Failed prefetches are remembered
     * for this request so the getters do not retry the same unavailable server.
     *
     * @param  array<int, string>  $types
     */
    public function prefetchMetrics(array $types, int $mins): void
    {
        $server = $this->getMetricsServer();
        if (! $server->isMetricsEnabled()) {
            return;
        }

        foreach ($types as $type) {
            $this->prefetchedMetricResponses["{$type}:{$mins}"] = null;
        }

        $from = now()->subMinutes($mins)->toIso8601ZuluString();
        $token = $this->metricsToken($server);
        // The token reaches curl on stdin (`-H @-`), so it is not a process argument. The token
        // only has letters, digits and `._-+/=` (ServerSetting::isValidSentinelToken), so it
        // cannot end the heredoc. Responses are separated by a 0x1E record separator.
        $script = implode("\nprintf '\\036'\n", array_map(
            fn (string $type) => 'curl -sS --connect-timeout 2 --max-time 3 -H @- "'.$this->getMetricsEndpoint($type, $from, $mins)."\" <<'COOLIFY_SENTINEL_AUTH'\nAuthorization: Bearer {$token}\nCOOLIFY_SENTINEL_AUTH",
            $types
        ));

        $output = SentinelMetricsClient::fetchOverSsh(
            $server,
            ["docker exec -i coolify-sentinel sh -s <<'COOLIFY_SENTINEL_SCRIPT'\n{$script}\nCOOLIFY_SENTINEL_SCRIPT"],
            static::class.':'.$this->uuid.':'.implode(',', $types).':'.$mins
        );
        if ($output === null) {
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

    /**
     * The getters read the $mins minutes before $to, or before now when $to is null.
     */
    public function getCpuMetrics(int $mins = 5, ?CarbonInterface $to = null): ?array
    {
        return $this->getMetrics('cpu', $mins, 'percent', $to);
    }

    public function getMemoryMetrics(int $mins = 5, ?CarbonInterface $to = null): ?array
    {
        if ($this->isServerMetrics()) {
            return $this->getMetrics('memory', $mins, 'usedPercent', $to);
        }

        $metrics = $this->getMetrics('memory', $mins, 'used', $to);
        if ($metrics === null) {
            return null;
        }

        return convertContainerMemoryBytesToMegabytes($metrics);
    }

    /**
     * Root filesystem usage percentage over time. Disk history interleaves every
     * mount, so we keep only the root mount (see SentinelMetricsClient::rootMountName()).
     */
    public function getDiskMetrics(int $mins = 5): ?array
    {
        $rows = $this->fetchMetricRows('disk', $mins);
        if ($rows === null) {
            return null;
        }

        if (array_any($rows, fn ($row) => array_key_exists('mount', $row))) {
            $mount = SentinelMetricsClient::rootMountName($rows);
            $rows = array_values(array_filter($rows, fn ($row) => ($row['mount'] ?? null) === $mount));
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
        return $this->getServerMetrics('network', $mins);
    }

    /**
     * Host metrics of one SERVER_METRIC_TYPES type: one [timestampMs, value] series, or an
     * array of series keyed by output key or by mount. Null when metrics are disabled.
     */
    public function getServerMetrics(string $type, int $mins = 5, ?CarbonInterface $to = null): ?array
    {
        $spec = self::SERVER_METRIC_TYPES[$type];
        $rows = $this->fetchMetricRows($type, $mins, $to);
        if ($rows === null) {
            return null;
        }

        if (isset($spec['groupBy'])) {
            return collect($rows)
                ->groupBy(fn (array $row) => (string) ($row[$spec['groupBy']] ?? ''))
                ->map(fn ($group) => $this->mapRows($group->values()->all(), $spec['field'], $mins))
                ->all();
        }

        if (isset($spec['fields'])) {
            return array_map(fn (string $field) => $this->mapRows($rows, $field, $mins), $spec['fields']);
        }

        return $this->mapRows($rows, $spec['field'], $mins);
    }

    private function getMetrics(string $type, int $mins, string $valueField, ?CarbonInterface $to = null): ?array
    {
        return $this->mapRows($this->fetchMetricRows($type, $mins, $to), $valueField, $mins);
    }

    /**
     * Fetch and decode the raw Sentinel history rows for a metric type, or null when
     * metrics are disabled. Throws on a Sentinel error response (caller decides whether
     * to treat that as fatal or optional).
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function fetchMetricRows(string $type, int $mins, ?CarbonInterface $to = null): ?array
    {
        $server = $this->getMetricsServer();
        if (! $server->isMetricsEnabled()) {
            return null;
        }

        // prefetchMetrics() reads only windows that end now.
        $key = "{$type}:{$mins}";
        if ($to === null && array_key_exists($key, $this->prefetchedMetricResponses)) {
            $response = $this->prefetchedMetricResponses[$key];
            unset($this->prefetchedMetricResponses[$key]);
        } else {
            $from = ($to ?? now())->copy()->subMinutes($mins)->toIso8601ZuluString();
            $endpoint = $this->getMetricsEndpoint($type, $from, $mins, $to?->toIso8601ZuluString());
            $token = $this->metricsToken($server);

            $command = "curl -sS --connect-timeout 2 --max-time 3 -H @- \"{$endpoint}\" <<'COOLIFY_SENTINEL_AUTH'\nAuthorization: Bearer {$token}\nCOOLIFY_SENTINEL_AUTH";
            $response = SentinelMetricsClient::fetchOverSsh(
                $server,
                ["docker exec -i coolify-sentinel sh -s <<'COOLIFY_SENTINEL_SCRIPT'\n{$command}\nCOOLIFY_SENTINEL_SCRIPT"],
                static::class.':'.$this->uuid.':'.$type.':'.$mins.($to ? ':'.$to->getTimestamp() : '')
            );
        }

        if ($response === null) {
            return null;
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

    /**
     * Ranges over an hour ask Sentinel to average rows into whole-minute windows, at most
     * 1000 per range, so it sends far fewer rows. Older Sentinels ignore `bucket_ms`;
     * mapRows() still downsamples their raw rows.
     */
    private function getMetricsEndpoint(string $type, string $from, int $mins, ?string $to = null): string
    {
        $base = 'http://localhost:8888/api';
        $query = "from={$from}";
        if ($to !== null) {
            $query .= "&to={$to}";
        }
        if ($mins > 60) {
            $query .= '&bucket_ms='.((int) ceil($mins / 1000) * 60_000);
        }

        if ($this->isServerMetrics()) {
            return "{$base}/{$type}/history?{$query}";
        }

        return "{$base}/container/{$this->uuid}/{$type}/history?{$query}";
    }
}
