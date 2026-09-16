<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

class SentinelMetricsClient
{
    private string $base = 'http://localhost:8888/api';

    public function __construct(protected Server $server) {}

    public static function rangeFrom(string $range): string
    {
        $from = match ($range) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDay(),
        };

        return $from->toIso8601ZuluString();
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
        }, $rows));
    }

    /**
     * @return array<int, array{0: int, 1: float}>
     */
    public function history(string $metric, string $from): array
    {
        [$path, $field] = match ($metric) {
            'memory' => ['/memory/history', 'used'],
            'disk' => ['/disk/history', 'usedPercent'],
            'load' => ['/load/history', 'load1'],
            default => ['/cpu/history', 'percent'],
        };

        return $this->seriesRows($this->url($path, ['from' => $from]), $field);
    }

    /**
     * @return array{rx: array<int, array{0: int, 1: float}>, tx: array<int, array{0: int, 1: float}>}
     */
    public function networkHistory(string $from): array
    {
        $url = $this->url('/network/history', ['from' => $from]);

        return [
            'rx' => $this->seriesRows($url, 'rxBytesPerSec'),
            'tx' => $this->seriesRows($url, 'txBytesPerSec'),
        ];
    }

    /**
     * @return array<int, array{0: int, 1: float}>
     */
    private function seriesRows(string $url, string $field): array
    {
        try {
            $rows = json_decode($this->raw($url), true);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_map(
            fn ($r) => [(int) ($r['time'] ?? 0), (float) ($r[$field] ?? 0)],
            $rows
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $mounts
     * @return array{used: ?int, total: ?int, percent: ?float}
     */
    private function rootMount(array $mounts): array
    {
        if ($mounts === []) {
            return ['used' => null, 'total' => null, 'percent' => null];
        }

        $root = collect($mounts)->firstWhere('mount', '/') ?? $mounts[0];

        return [
            'used' => (int) ($root['used'] ?? 0),
            'total' => (int) ($root['total'] ?? 0),
            'percent' => (float) ($root['usedPercent'] ?? 0),
        ];
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
        return Cache::remember($this->cacheKey($url), 60, fn () => $this->guard($this->remoteFetch($url)));
    }

    protected function remoteFetch(string $url): string
    {
        $token = $this->server->settings->ensureValidSentinelToken();

        return instant_remote_process(
            ["docker exec coolify-sentinel sh -c 'curl -s -H \"Authorization: Bearer {$token}\" \"{$url}\"'"],
            $this->server,
            false
        );
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
