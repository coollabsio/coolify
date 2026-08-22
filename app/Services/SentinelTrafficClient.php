<?php

namespace App\Services;

use App\Data\Traffic\TrafficBreakdownData;
use App\Data\Traffic\TrafficOverviewData;
use App\Data\Traffic\TrafficPathData;
use App\Data\Traffic\TrafficSeriesBucketData;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SentinelTrafficClient
{
    private string $base = 'http://localhost:8888/api';

    /** Record separator (0x1E) framing the batched curl responses in warm(). */
    private const RECORD_SEPARATOR = "\x1e";

    /** @var array<int, string> */
    private const ALLOWED_DIMENSIONS = [
        'status', 'method', 'country', 'referer', 'browser', 'os', 'device', 'protocol', 'scheme', 'tls', 'cache', 'bot', 'agent', 'ip', 'useragent',
    ];

    public function __construct(protected Server $server) {}

    // NOTE: Sentinel's traffic API expects `from`/`to` as ISO-8601 Zulu strings
    // (e.g. "2024-01-14T10:00:00Z"), confirmed against sentinel/API.md.
    public function overview(?string $appKey, string $from, string $to): TrafficOverviewData
    {
        $json = json_decode($this->raw($this->overviewUrl($appKey, $from, $to)), true) ?? [];

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
        $rows = json_decode($this->raw($this->pathsUrl($appKey, $from, $to, $limit)), true) ?? [];

        return collect($rows)->map(fn ($r) => TrafficPathData::fromSentinel($r));
    }

    public function breakdown(?string $appKey, string $dimension, string $from, string $to, int $limit = 50): Collection
    {
        $rows = json_decode($this->raw($this->breakdownUrl($appKey, $dimension, $from, $to, $limit)), true) ?? [];

        return collect($rows)->map(fn ($r) => TrafficBreakdownData::fromSentinel($r));
    }

    /**
     * Per-bucket status-class time series for the stacked-area chart.
     *
     * The series endpoints take a single `range` knob (24h/7d/30d) rather than
     * from/to, and always return a fixed-length, zero-filled array when present.
     * An older Sentinel without the route answers 404 (empty/non-array body);
     * we return an empty collection in that case so callers can gracefully fall
     * back to the donut instead of surfacing an error.
     *
     * @return Collection<int, TrafficSeriesBucketData>
     */
    public function series(?string $appKey, string $range = '24h'): Collection
    {
        $rows = json_decode($this->raw($this->seriesUrl($appKey, $range)), true);

        if (! is_array($rows) || $rows === []) {
            return collect();
        }

        return collect($rows)->map(fn ($r) => TrafficSeriesBucketData::fromSentinel($r));
    }

    public function apps(): array
    {
        return json_decode($this->raw($this->appsUrl()), true) ?? [];
    }

    public function attribution(): ?string
    {
        $json = json_decode($this->raw($this->attributionUrl()), true) ?? [];

        return data_get($json, 'attribution');
    }

    /**
     * Warm the 60s response cache for every endpoint the dashboard reads, in as few SSH
     * round-trips as possible. Prefers Sentinel's aggregate `/traffic/dashboard` (one call
     * that returns every shape, including the per-app leaderboard), and falls back to a
     * single batched `docker exec` over the individual endpoints when that route is absent
     * (older Sentinel). Best-effort: any failure leaves the per-call methods to fetch
     * individually. Returns the recorded app uuids so the caller can warm the per-app
     * overviews when the fallback path is taken.
     *
     * @param  array<int, string>  $dimensions
     * @return array<int, string>
     */
    public function prefetchServerWide(?string $appKey, string $from, string $to, array $dimensions, string $range, int $pathLimit = 50, int $breakdownLimit = 50, int $appsLimit = 200): array
    {
        $bundle = $this->fetchDashboard($appKey, $from, $to, $range, $pathLimit, $breakdownLimit, $appsLimit);
        if ($bundle !== null) {
            $this->seedFromDashboard($appKey, $from, $to, $range, $dimensions, $pathLimit, $breakdownLimit, $bundle);

            if ($appKey !== null) {
                return [];
            }

            return array_values(array_filter(
                array_map(fn ($app) => is_array($app) ? ($app['uuid'] ?? null) : null, $bundle['apps'] ?? []),
                fn ($uuid) => is_string($uuid) && $uuid !== ''
            ));
        }

        // Fallback for older Sentinel without /traffic/dashboard: batch the individual endpoints.
        $urls = [
            $this->overviewUrl($appKey, $from, $to),
            $this->pathsUrl($appKey, $from, $to, $pathLimit),
            $this->seriesUrl($appKey, $range),
            $this->attributionUrl(),
        ];
        foreach ($dimensions as $dimension) {
            $urls[] = $this->breakdownUrl($appKey, $dimension, $from, $to, $breakdownLimit);
        }
        // The per-application leaderboard only exists on the unfiltered view.
        if ($appKey === null) {
            $urls[] = $this->appsUrl();
        }

        $this->warm($urls);

        if ($appKey !== null) {
            return [];
        }

        return array_values(array_filter(
            $this->apps(),
            fn ($uuid) => is_string($uuid) && $uuid !== ''
        ));
    }

    /**
     * Fetch Sentinel's aggregate dashboard bundle, or null when the route is absent (older
     * Sentinel 404s) or the response isn't a real bundle. The bundle always carries an
     * `overview` member — even for an empty range — so its presence distinguishes a genuine
     * response from a stub/`{}`.
     *
     * @return array<string, mixed>|null
     */
    private function fetchDashboard(?string $appKey, string $from, string $to, string $range, int $pathLimit, int $breakdownLimit, int $appsLimit): ?array
    {
        // Older Sentinel 404s this route. raw() throws on that (and doesn't cache the failure),
        // so without a marker every refresh would re-probe over SSH before falling back to the
        // batch. Remember the absence for the same 60s window as the data cache: at most one
        // wasted probe per minute, and a Sentinel upgrade is picked up on the next window.
        $absenceKey = 'traffic:dashboard-absent:'.$this->server->uuid;
        if (Cache::get($absenceKey) === true) {
            return null;
        }

        try {
            $decoded = json_decode($this->raw($this->dashboardUrl($appKey, $from, $to, $range, $pathLimit, $breakdownLimit, $appsLimit)), true);
        } catch (\Throwable) {
            Cache::put($absenceKey, true, 60);

            return null;
        }

        if (! is_array($decoded) || ! array_key_exists('overview', $decoded)) {
            Cache::put($absenceKey, true, 60);

            return null;
        }

        return $decoded;
    }

    /**
     * Decompose the aggregate bundle back into the per-endpoint response cache, so the
     * existing per-call methods (overview/paths/breakdown/series/attribution and each
     * leaderboard app's overview) read it as a cache hit — the whole page from one fetch.
     *
     * @param  array<int, string>  $dimensions
     * @param  array<string, mixed>  $bundle
     */
    private function seedFromDashboard(?string $appKey, string $from, string $to, string $range, array $dimensions, int $pathLimit, int $breakdownLimit, array $bundle): void
    {
        $put = fn (string $url, $member) => Cache::put($this->cacheKey($url), json_encode($member), 60);

        $put($this->overviewUrl($appKey, $from, $to), $bundle['overview'] ?? []);
        $put($this->pathsUrl($appKey, $from, $to, $pathLimit), $bundle['paths'] ?? []);
        $put($this->seriesUrl($appKey, $range), $bundle['series'] ?? []);
        $put($this->attributionUrl(), ['attribution' => $bundle['attribution'] ?? null]);

        $breakdowns = $bundle['breakdowns'] ?? [];
        foreach ($dimensions as $dimension) {
            $put($this->breakdownUrl($appKey, $dimension, $from, $to, $breakdownLimit), $breakdowns[$dimension] ?? []);
        }

        foreach ($bundle['apps'] ?? [] as $app) {
            $uuid = is_array($app) ? ($app['uuid'] ?? null) : null;
            if (is_string($uuid) && $uuid !== '' && isset($app['overview'])) {
                $put($this->overviewUrl($uuid, $from, $to), $app['overview']);
            }
        }
    }

    /**
     * Warm the per-app overview cache for the leaderboard in one batched exec.
     *
     * @param  array<int, string>  $appKeys
     */
    public function prefetchAppOverviews(array $appKeys, string $from, string $to): void
    {
        $urls = array_map(fn ($appKey) => $this->overviewUrl($appKey, $from, $to), $appKeys);

        $this->warm($urls);
    }

    private function overviewUrl(?string $appKey, string $from, string $to): string
    {
        $path = $this->appScopedPath($appKey, 'overview');

        return $this->url($path, ['from' => $from, 'to' => $to]);
    }

    private function pathsUrl(?string $appKey, string $from, string $to, int $limit): string
    {
        $path = $this->appScopedPath($appKey, 'paths');

        return $this->url($path, ['from' => $from, 'to' => $to, 'limit' => (int) $limit]);
    }

    private function breakdownUrl(?string $appKey, string $dimension, string $from, string $to, int $limit): string
    {
        $this->assertSafeDimension($dimension);
        $path = $this->appScopedPath($appKey, "breakdown/{$dimension}");

        return $this->url($path, ['from' => $from, 'to' => $to, 'limit' => (int) $limit]);
    }

    private function seriesUrl(?string $appKey, string $range): string
    {
        $range = in_array($range, ['24h', '7d', '30d'], true) ? $range : '24h';
        $path = $this->appScopedPath($appKey, 'series');

        return $this->url($path, ['range' => $range]);
    }

    private function dashboardUrl(?string $appKey, string $from, string $to, string $range, int $pathLimit, int $breakdownLimit, int $appsLimit): string
    {
        $range = in_array($range, ['24h', '7d', '30d'], true) ? $range : '24h';
        $query = [
            'from' => $from,
            'to' => $to,
            'range' => $range,
            'paths_limit' => (int) $pathLimit,
            'breakdown_limit' => (int) $breakdownLimit,
        ];
        if ($appKey === null) {
            // apps_limit only applies to the server-wide leaderboard.
            $query['apps_limit'] = (int) $appsLimit;

            return $this->url('/traffic/dashboard', $query);
        }
        $this->assertSafeKey($appKey);

        return $this->url("/app/{$appKey}/traffic/dashboard", $query);
    }

    private function appsUrl(): string
    {
        return $this->url('/traffic/apps');
    }

    private function attributionUrl(): string
    {
        return $this->url('/traffic/attribution');
    }

    /**
     * Build a traffic path, optionally scoped to a single (validated) app key.
     */
    private function appScopedPath(?string $appKey, string $suffix): string
    {
        if ($appKey === null) {
            return "/traffic/{$suffix}";
        }
        $this->assertSafeKey($appKey);

        return "/app/{$appKey}/traffic/{$suffix}";
    }

    /**
     * Reject anything that isn't a bare CUID2/UUID or hostname before it is
     * interpolated into a shell-quoted `docker exec ... curl` command
     * (see remoteFetch()/buildFetchCommand()). No quotes, spaces, slashes, or
     * shell metacharacters.
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

    private function cacheKey(string $url): string
    {
        return 'traffic:'.$this->server->uuid.':'.md5($url);
    }

    /**
     * True when warm() may issue its batched exec: either raw() is the base (real transport),
     * or a subclass has explicitly overridden batchRemoteFetch to intercept the batch. A fake
     * that only overrides raw() returns false, so warm() stays off the wire.
     */
    private function usesBatchableTransport(): bool
    {
        if ((new \ReflectionMethod($this, 'raw'))->getDeclaringClass()->getName() === self::class) {
            return true;
        }

        return (new \ReflectionMethod($this, 'batchRemoteFetch'))->getDeclaringClass()->getName() !== self::class;
    }

    protected function raw(string $url): string
    {
        return Cache::remember($this->cacheKey($url), 60, fn () => $this->guard($this->remoteFetch($url)));
    }

    /**
     * Fetch several URLs in one `docker exec` and warm each one's response cache under the
     * same key raw() reads, so the subsequent per-call methods become cache hits. Cache hits
     * are skipped, individual error/invalid responses are left uncached (the per-call fetch
     * surfaces them), and any transport failure is swallowed — warming is an optimization,
     * never a correctness dependency.
     *
     * @param  array<int, string>  $urls
     */
    protected function warm(array $urls): void
    {
        // Batching only helps when raw() uses the real remote transport. A subclass that
        // overrides raw() to serve canned bodies (a test fake) — but not batchRemoteFetch —
        // would otherwise reach real SSH here; skip and let its raw() answer each call.
        if (! $this->usesBatchableTransport()) {
            return;
        }

        $misses = array_values(array_filter($urls, fn ($url) => ! Cache::has($this->cacheKey($url))));
        if ($misses === []) {
            return;
        }

        try {
            $output = $this->batchRemoteFetch($misses);
        } catch (\Throwable) {
            return;
        }

        $bodies = explode(self::RECORD_SEPARATOR, $output);
        foreach ($misses as $index => $url) {
            $body = $bodies[$index] ?? '';
            try {
                Cache::put($this->cacheKey($url), $this->guard($body), 60);
            } catch (\Throwable) {
                // Invalid/error body: leave uncached so raw() re-fetches and reports it.
            }
        }
    }

    protected function remoteFetch(string $url): string
    {
        $token = $this->server->settings->ensureValidSentinelToken();

        return instant_remote_process(
            [$this->buildFetchCommand($token, $url)],
            $this->server,
            false
        );
    }

    /**
     * @param  array<int, string>  $urls
     */
    protected function batchRemoteFetch(array $urls): string
    {
        $token = $this->server->settings->ensureValidSentinelToken();

        return instant_remote_process(
            [$this->buildBatchCommand($token, $urls)],
            $this->server,
            false
        );
    }

    /**
     * Build the `docker exec ... curl` command run inside the Sentinel container.
     *
     * The URL is double-quoted inside the inner `sh -c` string so the literal `&`
     * between the `from`/`to` (and `limit`) query params is not interpreted as a
     * shell background operator — which would background curl after `from=...` and
     * truncate every multi-param request. The app key and dimension are validated
     * (assertSafeKey/assertSafeDimension) before reaching here, so the URL cannot
     * contain shell metacharacters that break out of the quoting.
     */
    protected function buildFetchCommand(string $token, string $url): string
    {
        return "docker exec coolify-sentinel sh -c 'curl -H \"Authorization: Bearer {$token}\" \"{$url}\"'";
    }

    /**
     * Build one `docker exec` that curls every URL in order and separates the responses
     * with a 0x1E record separator, so warm() can split them back apart. escapeshellarg
     * safely wraps the whole script; each URL stays double-quoted so its `&` is literal.
     *
     * @param  array<int, string>  $urls
     */
    protected function buildBatchCommand(string $token, array $urls): string
    {
        $script = implode(' ; ', array_map(
            fn ($url) => "curl -s -H \"Authorization: Bearer {$token}\" \"{$url}\" ; printf '\\036'",
            $urls
        ));

        return 'docker exec coolify-sentinel sh -c '.escapeshellarg($script);
    }

    private function guard(string $response): string
    {
        $payload = json_decode($response, true);

        if (! is_array($payload)) {
            throw new \RuntimeException('Traffic analytics returned an invalid response.');
        }

        if (array_key_exists('error', $payload)) {
            $error = data_get($payload, 'error');
            throw new \RuntimeException(is_string($error) ? $error : 'Traffic analytics request failed.');
        }

        return $response;
    }
}
