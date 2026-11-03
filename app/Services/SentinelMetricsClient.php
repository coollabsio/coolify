<?php

namespace App\Services;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

class SentinelMetricsClient
{
    private const REMOTE_TIMEOUT = 20;

    /** Mounts that can hold the root disk, in the order of rootMountName(). */
    private const ROOT_MOUNTS = ['/', '/app/db', '/data/coolify', '/data'];

    private string $base = 'http://localhost:8888/api';

    /** @var array<string, ?string> url => response from prefetchConcurrently(); null when it failed */
    private array $prefetched = [];

    public function __construct(protected Server $server) {}

    /**
     * Fetch the Fleet Metrics responses of several servers at the same time, with one SSH
     * command per server, so a page waits for the slowest server only. raw() then reads the
     * responses; a failed fetch is not tried again per metric.
     *
     * @param  iterable<int|string, self>  $clients
     */
    public static function prefetchConcurrently(iterable $clients, string $range): void
    {
        $started = [];
        foreach ($clients as $client) {
            // A subclass is a test fake with canned data; it must not reach real SSH.
            if ($client::class !== self::class) {
                continue;
            }
            $urls = array_values(array_filter($client->pageUrls($range), fn (string $url) => ! Cache::has($client->cacheKey($url))));
            if ($urls === []) {
                continue;
            }

            try {
                $started[] = [$client, $urls, $client->startBatch($urls)];
            } catch (\Throwable) {
                $client->prefetched += array_fill_keys($urls, null);
            }
        }

        foreach ($started as [$client, $urls, $process]) {
            try {
                $responses = explode("\x1e", $client->awaitBatch($process));
            } catch (\Throwable) {
                $responses = [];
            }
            if (count($responses) !== count($urls)) {
                $responses = [];
            }

            foreach ($urls as $index => $url) {
                try {
                    $body = $client->guard(trim($responses[$index] ?? ''));
                    Cache::put($client->cacheKey($url), $body, 60);
                    $client->prefetched[$url] = $body;
                } catch (\Throwable) {
                    $client->prefetched[$url] = null;
                }
            }
        }
    }

    /**
     * Every URL that the Fleet Metrics page reads for one server.
     *
     * @return array<int, string>
     */
    private function pageUrls(string $range): array
    {
        return [
            $this->url('/summary'),
            $this->url('/containers/current'),
            ...array_map(fn (string $metric) => $this->historyUrl($metric, $range), ['cpu', 'memory', 'disk', 'load', 'network']),
        ];
    }

    /**
     * Optional charts must not occupy web workers for the general SSH timeout.
     * One in-flight read per server, a short shared response cache, and a cooldown
     * keep repeated dashboard refreshes from reconnecting to an unavailable server.
     */
    public static function fetchOverSsh(Server $server, array $commands, string $cacheKey): ?string
    {
        $prefix = 'sentinel:metrics-ssh:'.$server->id;
        $responseKey = $prefix.':response:'.hash('sha256', $cacheKey);
        if (Cache::has($prefix.':unavailable')) {
            return null;
        }
        $cached = Cache::get($responseKey);
        if (is_string($cached)) {
            return $cached;
        }

        $lock = Cache::lock($prefix.':inflight', 15);
        if (! $lock->get()) {
            return null;
        }

        try {
            // Another node may have completed a fetch while this lock was acquired.
            if (Cache::has($prefix.':unavailable')) {
                return null;
            }
            $cached = Cache::get($responseKey);
            if (is_string($cached)) {
                return $cached;
            }

            try {
                $response = instant_remote_process(
                    $commands,
                    $server,
                    timeout: 8,
                    // Mux setup and health checks have separate, longer timeouts.
                    disableMultiplexing: true,
                    maxAttempts: 1
                );
                if ($response === null || trim($response) === '') {
                    throw new \RuntimeException('Metrics returned an empty response.');
                }
            } catch (\Throwable) {
                Cache::put($prefix.':unavailable', true, 60);

                return null;
            }

            Cache::put($responseKey, $response, 10);

            return $response;
        } finally {
            $lock->release();
        }
    }

    /** Rounded to the minute so the history URL (and its cache key) is stable within a minute. */
    public static function rangeFrom(string $range): string
    {
        $now = now()->startOfMinute();

        $from = match ($range) {
            '7d' => $now->subDays(7),
            '30d' => $now->subDays(30),
            default => $now->subDay(),
        };

        return $from->toIso8601ZuluString();
    }

    /** Bucket width per range, kept at roughly 300 points per series. */
    public static function bucketMs(string $range): int
    {
        return match ($range) {
            '7d' => 30 * 60_000,
            '30d' => 2 * 60 * 60_000,
            default => 5 * 60_000,
        };
    }

    /**
     * Sentinel averages rows into `bucket_ms` windows. Older Sentinels ignore the param
     * and return raw rows, which seriesRows() buckets the same way.
     *
     * @return array{from: string, bucket_ms: int}
     */
    private static function historyQuery(string $range): array
    {
        return ['from' => self::rangeFrom($range), 'bucket_ms' => self::bucketMs($range)];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function summary(): ?array
    {
        try {
            $json = json_decode($this->raw($this->url('/summary')), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($json)) {
            return null;
        }

        $disk = $this->rootMount($json['disk'] ?? []);

        return [
            'online' => true,
            'cpu' => isset($json['cpu']['percent']) ? (float) $json['cpu']['percent'] : null,
            'memUsed' => isset($json['memory']['used']) ? (int) $json['memory']['used'] : null,
            'memTotal' => isset($json['memory']['total']) ? (int) $json['memory']['total'] : null,
            'diskUsed' => $disk['used'],
            'diskTotal' => $disk['total'],
            'diskPercent' => $disk['percent'],
            'load1' => isset($json['load']['load1']) ? (float) $json['load']['load1'] : null,
            'netRx' => isset($json['network']['rxBytesPerSec']) ? (float) $json['network']['rxBytesPerSec'] : null,
            'netTx' => isset($json['network']['txBytesPerSec']) ? (float) $json['network']['txBytesPerSec'] : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function containersCurrent(): array
    {
        try {
            $rows = json_decode($this->raw($this->url('/containers/current')), true);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($rows)) {
            return [];
        }

        // Sentinel keeps the last samples of a removed container until retention deletes them,
        // but only a container that Docker still lists has a status.
        $running = array_filter($rows, fn ($c) => ($c['status']['state'] ?? null) === 'running');

        return array_values(array_map(function ($c) {
            $disk = ($c['disk']['writableLayer'] ?? 0) + ($c['disk']['volumesTotal'] ?? 0);
            $net = ($c['network']['rxBytesPerSec'] ?? 0) + ($c['network']['txBytesPerSec'] ?? 0);

            return [
                'id' => (string) ($c['id'] ?? ''),
                'cpu' => isset($c['cpu']['percent']) ? (float) $c['cpu']['percent'] : null,
                'memUsed' => isset($c['memory']['used']) ? (int) $c['memory']['used'] : null,
                'memPercent' => isset($c['memory']['usedPercent']) ? (float) $c['memory']['usedPercent'] : null,
                'diskBytes' => isset($c['disk']) ? (int) $disk : null,
                'net' => isset($c['network']) ? (float) $net : null,
            ];
        }, $running));
    }

    /**
     * @return array<int, array{0: int, 1: float}>
     */
    public function history(string $metric, string $range): array
    {
        $field = match ($metric) {
            'memory' => 'used',
            'disk' => 'usedPercent',
            'load' => 'load1',
            default => 'percent',
        };

        return $this->seriesRows($this->historyUrl($metric, $range), $field, $range, $metric === 'disk');
    }

    /**
     * @return array{rx: array<int, array{0: int, 1: float}>, tx: array<int, array{0: int, 1: float}>}
     */
    public function networkHistory(string $range): array
    {
        $url = $this->historyUrl('network', $range);

        return [
            'rx' => $this->seriesRows($url, 'rxBytesPerSec', $range),
            'tx' => $this->seriesRows($url, 'txBytesPerSec', $range),
        ];
    }

    /**
     * @return array<int, array{0: int, 1: float}>
     */
    private function seriesRows(string $url, string $field, string $range, bool $rootOnly = false): array
    {
        try {
            $rows = json_decode($this->raw($url), true);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($rows)) {
            return [];
        }

        // Disk history interleaves every mount; keep the root filesystem only.
        if ($rootOnly && array_any($rows, fn ($row) => array_key_exists('mount', $row))) {
            $mount = self::rootMountName($rows);
            $rows = array_filter($rows, fn ($row) => ($row['mount'] ?? null) === $mount);
        }

        // Average samples into epoch-aligned buckets so every server shares the same
        // timestamps and the fleet aggregator can sum them point by point. A no-op for
        // rows Sentinel already bucketed.
        $bucketMs = self::bucketMs($range);
        $sums = [];
        $counts = [];
        foreach ($rows as $r) {
            $bucket = intdiv((int) ($r['time'] ?? 0), $bucketMs) * $bucketMs;
            $sums[$bucket] = ($sums[$bucket] ?? 0.0) + (float) ($r[$field] ?? 0);
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
        }
        ksort($sums);

        return array_map(fn ($bucket) => [$bucket, round($sums[$bucket] / $counts[$bucket], 2)], array_keys($sums));
    }

    /**
     * The mount that stands for the root disk: `/`, else the disk that holds /data/coolify.
     * Sentinel runs in a container and reports only the disks of its own mounts:
     * 1.1.2 and older as `/app/db`, 1.1.3 and newer by host mount point, which is
     * `/data` or `/data/coolify` when that directory is on its own disk.
     * Sentinel 1.1.3 names a disk by its shortest host mount point, which can be any
     * path (for example `/home` when `/data` is a symlink into a NAS volume). When none
     * of ROOT_MOUNTS is reported, the only reported disk is the one under Sentinel's
     * `/app/db`; with several, the right one is unknown.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function rootMountName(array $rows): ?string
    {
        $mounts = array_values(array_unique(array_column($rows, 'mount')));

        return collect(self::ROOT_MOUNTS)->first(fn ($mount) => in_array($mount, $mounts, true))
            ?? (count($mounts) === 1 ? $mounts[0] : null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $mounts
     * @return array{used: ?int, total: ?int, percent: ?float}
     */
    private function rootMount(array $mounts): array
    {
        if (array_any($mounts, fn ($mount) => array_key_exists('mount', $mount))) {
            $root = collect($mounts)->firstWhere('mount', self::rootMountName($mounts));
        } else {
            $root = $mounts[0] ?? null;
        }

        if ($root === null) {
            return ['used' => null, 'total' => null, 'percent' => null];
        }

        return [
            'used' => (int) ($root['used'] ?? 0),
            'total' => (int) ($root['total'] ?? 0),
            'percent' => (float) ($root['usedPercent'] ?? 0),
        ];
    }

    private function historyUrl(string $metric, string $range): string
    {
        $path = match ($metric) {
            'memory', 'disk', 'load', 'network' => "/{$metric}/history",
            default => '/cpu/history',
        };

        // No `mount` filter for disk history: rootMountName() can pick a disk that is not in
        // ROOT_MOUNTS, and Sentinel reports only the few disks of its own mounts.
        return $this->url($path, self::historyQuery($range));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function url(string $path, array $query = []): string
    {
        $qs = empty($query) ? '' : '?'.str_replace('%3A', ':', http_build_query($query));

        return $this->base.$path.$qs;
    }

    private function cacheKey(string $url): string
    {
        return 'metrics:'.$this->server->uuid.':'.md5($url);
    }

    protected function raw(string $url): string
    {
        if (array_key_exists($url, $this->prefetched)) {
            return $this->prefetched[$url] ?? throw new \RuntimeException('Metrics request failed.');
        }

        return Cache::remember($this->cacheKey($url), 60, fn () => $this->guard($this->remoteFetch($url)));
    }

    protected function remoteFetch(string $url): string
    {
        $token = $this->server->settings->ensureValidSentinelToken();

        $command = "curl -sS --connect-timeout 2 --max-time 3 -H @- \"{$url}\" <<'COOLIFY_SENTINEL_AUTH'\nAuthorization: Bearer {$token}\nCOOLIFY_SENTINEL_AUTH";

        return self::fetchOverSsh(
            $this->server,
            ["docker exec -i coolify-sentinel sh -s <<'COOLIFY_SENTINEL_SCRIPT'\n{$command}\nCOOLIFY_SENTINEL_SCRIPT"],
            $url
        ) ?? '';
    }

    /**
     * Start one `docker exec` that curls every URL in order, with the responses separated by
     * a 0x1E record separator. The token reaches curl on stdin, so it is not a process argument.
     * The token only has letters, digits and `._-+/=` (ServerSetting::isValidSentinelToken), so it
     * cannot end the heredoc. No pipe or `sh -c` is used, so the non-root sudo parser only puts
     * sudo in front of the line. Unlike instant_remote_process(), there are no SSH retries.
     *
     * @param  array<int, string>  $urls
     */
    private function startBatch(array $urls): InvokedProcess
    {
        $token = $this->server->settings->ensureValidSentinelToken();
        $script = implode("\nprintf '\\036'\n", array_map(
            fn (string $url) => "curl -s --connect-timeout 3 --max-time 10 -H @- \"{$url}\" <<'COOLIFY_SENTINEL_AUTH'\nAuthorization: Bearer {$token}\nCOOLIFY_SENTINEL_AUTH",
            $urls
        ));
        // `true` last: a failed curl empties only its own response, not the whole batch.
        $command = "docker exec -i coolify-sentinel sh -s <<'COOLIFY_SENTINEL_SCRIPT'\n{$script}\ntrue\nCOOLIFY_SENTINEL_SCRIPT";
        $commands = $this->server->isNonRoot() ? parseCommandsByLineForSudo(collect([$command]), $this->server) : [$command];

        return Process::timeout(self::REMOTE_TIMEOUT)->start(
            SshMultiplexingHelper::generateSshCommand($this->server, implode("\n", $commands), commandTimeout: self::REMOTE_TIMEOUT)
        );
    }

    private function awaitBatch(InvokedProcess $invoked): string
    {
        $process = $invoked->wait();
        if ($process->exitCode() !== 0) {
            excludeCertainErrors($process->errorOutput(), $process->exitCode());
        }

        return $process->output();
    }

    private function guard(string $response): string
    {
        $payload = json_decode($response, true);

        if ($payload === null) {
            throw new \RuntimeException('Metrics returned an invalid response.');
        }

        if (is_array($payload) && array_key_exists('error', $payload)) {
            throw new \RuntimeException('Metrics request failed.');
        }

        return $response;
    }
}
