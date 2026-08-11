<?php

namespace App\Services;

use App\Data\Traffic\TrafficBreakdownData;
use App\Data\Traffic\TrafficOverviewData;
use App\Data\Traffic\TrafficPathData;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SentinelTrafficClient
{
    private string $base = 'http://localhost:8888/api';

    /** @var array<int, string> */
    private const ALLOWED_DIMENSIONS = [
        'status', 'method', 'country', 'referer', 'browser', 'os', 'device', 'protocol', 'scheme', 'tls', 'cache', 'bot',
    ];

    public function __construct(protected Server $server) {}

    // NOTE: Sentinel's traffic API expects `from`/`to` as ISO-8601 Zulu strings
    // (e.g. "2024-01-14T10:00:00Z"), confirmed against sentinel/API.md.
    public function overview(?string $appKey, string $from, string $to): TrafficOverviewData
    {
        if ($appKey !== null) {
            $this->assertSafeKey($appKey);
        }
        $path = $appKey ? "/app/{$appKey}/traffic/overview" : '/traffic/overview';
        $json = json_decode($this->raw($this->url($path, ['from' => $from, 'to' => $to])), true) ?? [];

        return TrafficOverviewData::fromSentinel($json);
    }

    /**
     * Convert a UI range key (24h/7d/30d) into ISO-8601 Zulu from/to bounds.
     *
     * @return array{0: string, 1: string}
     */
    public static function rangeWindow(string $range): array
    {
        $to = now();
        $from = match ($range) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDay(),
        };

        return [$from->toIso8601ZuluString(), $to->toIso8601ZuluString()];
    }

    /**
     * Slim shared fetch for a single application's overview over a UI range, so the
     * General-page widget and the full analytics tab don't duplicate window + client calls.
     */
    public function appOverview(string $appKey, string $range = '24h'): TrafficOverviewData
    {
        [$from, $to] = self::rangeWindow($range);

        return $this->overview($appKey, $from, $to);
    }

    public function paths(?string $appKey, string $from, string $to, int $limit = 50): Collection
    {
        if ($appKey !== null) {
            $this->assertSafeKey($appKey);
        }
        $path = $appKey ? "/app/{$appKey}/traffic/paths" : '/traffic/paths';
        $rows = json_decode($this->raw($this->url($path, ['from' => $from, 'to' => $to, 'limit' => (int) $limit])), true) ?? [];

        return collect($rows)->map(fn ($r) => TrafficPathData::fromSentinel($r));
    }

    public function breakdown(?string $appKey, string $dimension, string $from, string $to, int $limit = 50): Collection
    {
        if ($appKey !== null) {
            $this->assertSafeKey($appKey);
        }
        $this->assertSafeDimension($dimension);
        $path = $appKey ? "/app/{$appKey}/traffic/breakdown/{$dimension}" : "/traffic/breakdown/{$dimension}";
        $rows = json_decode($this->raw($this->url($path, ['from' => $from, 'to' => $to, 'limit' => (int) $limit])), true) ?? [];

        return collect($rows)->map(fn ($r) => TrafficBreakdownData::fromSentinel($r));
    }

    public function apps(): array
    {
        return json_decode($this->raw($this->url('/traffic/apps')), true) ?? [];
    }

    public function attribution(): ?string
    {
        $json = json_decode($this->raw($this->url('/traffic/attribution')), true) ?? [];

        return data_get($json, 'attribution');
    }

    /**
     * Reject anything that isn't a bare CUID2/UUID or hostname before it is
     * interpolated into a shell-quoted `docker exec ... curl` command
     * (see remoteFetch()). No quotes, spaces, slashes, or shell metacharacters.
     */
    private function assertSafeKey(string $value): void
    {
        if ($value === '' || ! preg_match('/\A[A-Za-z0-9._:-]+\z/', $value)) {
            throw new \InvalidArgumentException('Invalid traffic analytics app key.');
        }
    }

    private function assertSafeDimension(string $dimension): void
    {
        if (! in_array($dimension, self::ALLOWED_DIMENSIONS, true)) {
            throw new \InvalidArgumentException('Invalid traffic analytics dimension.');
        }
    }

    private function url(string $path, array $query = []): string
    {
        // Colons in ISO-8601 Zulu timestamps are safe in a query string; keep them
        // unencoded to match Sentinel's expected `from`/`to` format.
        $qs = empty($query) ? '' : '?'.str_replace('%3A', ':', http_build_query($query));

        return $this->base.$path.$qs;
    }

    protected function raw(string $url): string
    {
        return Cache::remember('traffic:'.$this->server->uuid.':'.md5($url), 60, fn () => $this->guard($this->remoteFetch($url)));
    }

    protected function remoteFetch(string $url): string
    {
        $token = $this->server->settings->ensureValidSentinelToken();

        return instant_remote_process(
            ["docker exec coolify-sentinel sh -c 'curl -H \"Authorization: Bearer {$token}\" {$url}'"],
            $this->server,
            false
        );
    }

    private function guard(string $response): string
    {
        if (str($response)->contains('"error"')) {
            $error = data_get(json_decode($response, true), 'error', 'Traffic analytics request failed.');
            throw new \Exception($error);
        }

        return $response;
    }
}
