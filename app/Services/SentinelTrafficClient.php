<?php

namespace App\Services;

use App\Data\Traffic\TrafficBreakdownData;
use App\Data\Traffic\TrafficOverviewData;
use App\Data\Traffic\TrafficPathData;
use App\Data\Traffic\TrafficSeriesBucketData;
use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SentinelTrafficClient
{
    private string $base = 'http://localhost:8888/api';

    /** Record separator (0x1E) framing the batched curl responses in warm(). */
    private const RECORD_SEPARATOR = "\x1e";

    /**
     * Seconds to remember that this server's Sentinel has no `/api/resource/...` routes. Longer
     * than the data cache: every probe on an older Sentinel costs one or two wasted execs.
     */
    public const RESOURCE_SCOPE_ABSENCE_TTL = 300;

    /**
     * Matches the "Live" poll interval, so a dead server costs at most one timed-out fetch per minute.
     */
    public const UNAVAILABLE_TTL = 60;

    /** Upper bound in seconds of one SSH round-trip (connect, docker exec, every curl). */
    private const REMOTE_TIMEOUT = 20;

    /** Bounds each curl inside the Sentinel container, so a stuck Sentinel cannot hold the SSH session. */
    private const CURL_TIMEOUT_OPTIONS = '--connect-timeout 3 --max-time 10';

    /** Path segment of the per-key routes: `/api/app/{key}/traffic/...`. */
    private const SCOPE_APP = 'app';

    /**
     * Path segment of the resource routes: `/api/resource/{uuid}/traffic/...`. Sentinel merges
     * `{uuid}` and every `{uuid}-*` key exactly (HLL union for uniques, merged t-digests).
     */
    private const SCOPE_RESOURCE = 'resource';

    /** @var array<int, string> */
    private const ALLOWED_DIMENSIONS = [
        'status', 'method', 'country', 'referer', 'browser', 'os', 'device', 'protocol', 'scheme', 'tls', 'cache', 'bot', 'agent', 'ip', 'useragent',
    ];

    public function __construct(protected Server $server) {}

    // NOTE: Sentinel's traffic API expects `from`/`to` as ISO-8601 Zulu strings
    // (e.g. "2024-01-14T10:00:00Z"), confirmed against sentinel/API.md.
    public function overview(?string $appKey, string $from, string $to): TrafficOverviewData
    {
        return $this->fetchOverview($this->overviewUrl($appKey, $from, $to));
    }

    /**
     * Exact overview of one resource: every key of the resource merged by Sentinel.
     */
    public function resourceOverview(string $resourceUuid, string $from, string $to): TrafficOverviewData
    {
        return $this->fetchOverview($this->overviewUrl($resourceUuid, $from, $to, self::SCOPE_RESOURCE));
    }

    /**
     * Convert a UI range key (24h/7d/30d) into ISO-8601 Zulu from/to bounds.
     *
     * The window ends at the next full minute instead of the current second: the bounds are
     * part of the 60s response cache key, so every load within that minute reuses the cached
     * response. The window still covers "now" (Sentinel rolls traffic up per minute, so the
     * end in the future adds nothing) and spans exactly the requested range.
     *
     * @return array{0: string, 1: string}
     */
    public static function rangeWindow(string $range): array
    {
        $to = now()->utc()->ceilMinute();
        $from = match ($range) {
            '7d' => $to->copy()->subDays(7),
            '30d' => $to->copy()->subDays(30),
            default => $to->copy()->subDay(),
        };

        return [$from->toIso8601ZuluString(), $to->toIso8601ZuluString()];
    }

    public function paths(?string $appKey, string $from, string $to, int $limit = 50): Collection
    {
        return $this->fetchPaths($this->pathsUrl($appKey, $from, $to, $limit));
    }

    /**
     * Top paths of one resource. Each row keeps the real Sentinel key in its `app` field.
     */
    public function resourcePaths(string $resourceUuid, string $from, string $to, int $limit = 50): Collection
    {
        return $this->fetchPaths($this->pathsUrl($resourceUuid, $from, $to, $limit, self::SCOPE_RESOURCE));
    }

    public function breakdown(?string $appKey, string $dimension, string $from, string $to, int $limit = 50): Collection
    {
        return $this->fetchBreakdown($this->breakdownUrl($appKey, $dimension, $from, $to, $limit));
    }

    public function resourceBreakdown(string $resourceUuid, string $dimension, string $from, string $to, int $limit = 50): Collection
    {
        return $this->fetchBreakdown($this->breakdownUrl($resourceUuid, $dimension, $from, $to, $limit, self::SCOPE_RESOURCE));
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
        return $this->fetchSeries($this->seriesUrl($appKey, $range));
    }

    /**
     * @return Collection<int, TrafficSeriesBucketData>
     */
    public function resourceSeries(string $resourceUuid, string $range = '24h'): Collection
    {
        return $this->fetchSeries($this->seriesUrl($resourceUuid, $range, self::SCOPE_RESOURCE));
    }

    /**
     * False while this server's Sentinel is known to lack the resource routes; callers then
     * use the per-key path (resourceKeys() and a merge in TrafficAnalyticsAggregator).
     */
    public function supportsResourceScope(): bool
    {
        return Cache::get($this->resourceScopeAbsenceKey()) !== true;
    }

    /**
     * Exact overview of one resource, or null when Sentinel has no resource routes. A failed
     * probe is remembered (see RESOURCE_SCOPE_ABSENCE_TTL), so later calls return null at once.
     */
    public function tryResourceOverview(string $resourceUuid, string $from, string $to): ?TrafficOverviewData
    {
        $url = $this->overviewUrl($resourceUuid, $from, $to, self::SCOPE_RESOURCE);
        if (! $this->supportsResourceScope()) {
            return null;
        }

        $overview = $this->genuineOverview($url);
        if ($overview === null) {
            $this->markResourceScopeAbsent();

            return null;
        }

        return TrafficOverviewData::fromSentinel($overview);
    }

    /**
     * Warm every resource endpoint in one exec with the resource dashboard bundle. When the
     * bundle is unavailable, batch the individual resource endpoints instead. Returns false
     * (and remembers it) when Sentinel has no resource routes; the caller then uses the
     * per-key path with prefetchResource().
     *
     * @param  array<int, string>  $dimensions
     */
    public function prefetchResourceScope(string $resourceUuid, string $from, string $to, array $dimensions, string $range, int $pathLimit = 50, int $breakdownLimit = 50): bool
    {
        $this->assertSafeKey($resourceUuid);
        if (! $this->supportsResourceScope()) {
            return false;
        }

        $bundle = $this->fetchBundle($this->dashboardUrl($resourceUuid, $from, $to, $range, $pathLimit, $breakdownLimit, 0, self::SCOPE_RESOURCE));
        if ($bundle !== null) {
            $this->seedFromDashboard($resourceUuid, $from, $to, $range, $dimensions, $pathLimit, $breakdownLimit, $bundle, self::SCOPE_RESOURCE);

            return true;
        }

        $overviewUrl = $this->overviewUrl($resourceUuid, $from, $to, self::SCOPE_RESOURCE);
        $this->warm([
            ...$this->endpointUrls($resourceUuid, $from, $to, $dimensions, $range, $pathLimit, $breakdownLimit, self::SCOPE_RESOURCE),
            $this->attributionUrl(),
        ]);

        // After a real batch, an uncached overview means the route is absent: no second probe.
        $supported = (! $this->usesBatchableTransport() || Cache::has($this->cacheKey($overviewUrl)))
            && $this->genuineOverview($overviewUrl) !== null;

        if (! $supported) {
            $this->markResourceScopeAbsent();
        }

        return $supported;
    }

    public function apps(): array
    {
        return json_decode($this->raw($this->appsUrl()), true) ?? [];
    }

    /**
     * True when Sentinel key $key belongs to the resource with $resourceUuid. A resource
     * (Application or Service) owns the bare `{uuid}` key and every `{uuid}-…` key: compose
     * services (`{uuid}-{service}`), previews (`{uuid}-{pr}`), and preview compose services.
     */
    public static function keyBelongsTo(string $key, string $resourceUuid): bool
    {
        return $resourceUuid !== '' && ($key === $resourceUuid || str_starts_with($key, $resourceUuid.'-'));
    }

    /**
     * Uuids that could own $key, most specific first: the key itself, then every prefix
     * that ends before a `-`. Lets a caller match a key against a uuid map in O(key length).
     *
     * @return array<int, string>
     */
    public static function candidateOwnerUuids(string $key): array
    {
        $candidates = [$key];
        $position = strlen($key);
        while (($position = strrpos(substr($key, 0, $position), '-')) !== false) {
            if ($position > 0) {
                $candidates[] = substr($key, 0, $position);
            }
        }

        return $candidates;
    }

    /**
     * Every Sentinel key recorded on this server that belongs to the resource. Falls back to
     * the bare uuid when none is recorded (or the key list is unavailable), so per-key queries
     * still run and return an empty result instead of failing.
     *
     * @return array<int, string>
     */
    public function resourceKeys(string $resourceUuid): array
    {
        $this->assertSafeKey($resourceUuid);

        try {
            $recorded = $this->apps();
        } catch (\Throwable) {
            $recorded = [];
        }

        $keys = array_values(array_unique(array_filter(
            $recorded,
            fn ($key) => is_string($key)
                && $this->isSafeKey($key)
                && self::keyBelongsTo($key, $resourceUuid)
        )));
        sort($keys);

        return $keys === [] ? [$resourceUuid] : $keys;
    }

    /**
     * Resolve the resource's keys and warm every endpoint for each key, in as few SSH
     * round-trips as possible: the key list and the bare-uuid dashboard bundle (the common
     * single-key case) share one exec, and extra keys share one more.
     *
     * @param  array<int, string>  $dimensions
     * @return array<int, string>
     */
    public function prefetchResource(string $resourceUuid, string $from, string $to, array $dimensions, string $range, int $pathLimit = 50, int $breakdownLimit = 50): array
    {
        $this->assertSafeKey($resourceUuid);

        $urls = [$this->appsUrl()];
        if (Cache::get($this->dashboardAbsenceKey()) !== true) {
            $urls[] = $this->dashboardUrl($resourceUuid, $from, $to, $range, $pathLimit, $breakdownLimit, 0);
        }
        $this->warm($urls);

        $keys = $this->resourceKeys($resourceUuid);
        $this->prefetchKeys($keys, $from, $to, $dimensions, $range, $pathLimit, $breakdownLimit);

        return $keys;
    }

    /**
     * Warm every endpoint for several app keys. One key uses prefetchServerWide(); several
     * keys batch their dashboard bundles (or, on an older Sentinel, their individual
     * endpoints) into one exec instead of one SSH round-trip per key.
     *
     * @param  array<int, string>  $appKeys
     * @param  array<int, string>  $dimensions
     */
    public function prefetchKeys(array $appKeys, string $from, string $to, array $dimensions, string $range, int $pathLimit = 50, int $breakdownLimit = 50): void
    {
        $appKeys = array_values(array_unique($appKeys));
        if ($appKeys === []) {
            return;
        }

        if (count($appKeys) === 1 || ! $this->usesBatchableTransport()) {
            foreach ($appKeys as $appKey) {
                $this->prefetchServerWide($appKey, $from, $to, $dimensions, $range, $pathLimit, $breakdownLimit);
            }

            return;
        }

        if (Cache::get($this->dashboardAbsenceKey()) !== true) {
            $dashboardUrls = array_map(
                fn (string $appKey) => $this->dashboardUrl($appKey, $from, $to, $range, $pathLimit, $breakdownLimit, 0),
                $appKeys
            );
            $this->warm($dashboardUrls);

            if (Cache::has($this->cacheKey($dashboardUrls[0]))) {
                // Each call now reads its bundle from cache and seeds the per-endpoint cache.
                foreach ($appKeys as $appKey) {
                    $this->prefetchServerWide($appKey, $from, $to, $dimensions, $range, $pathLimit, $breakdownLimit);
                }

                return;
            }

            // Older Sentinel without the dashboard route: remember it like fetchDashboard() does.
            Cache::put($this->dashboardAbsenceKey(), true, 60);
        }

        $urls = [$this->attributionUrl()];
        foreach ($appKeys as $appKey) {
            array_push($urls, ...$this->endpointUrls($appKey, $from, $to, $dimensions, $range, $pathLimit, $breakdownLimit));
        }
        $this->warm($urls);
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

            return $this->safeReportedKeys(array_map(fn ($app) => is_array($app) ? ($app['uuid'] ?? null) : null, $bundle['apps'] ?? []));
        }

        // Fallback for older Sentinel without /traffic/dashboard: batch the individual endpoints.
        $urls = [
            ...$this->endpointUrls($appKey, $from, $to, $dimensions, $range, $pathLimit, $breakdownLimit),
            $this->attributionUrl(),
        ];
        // The per-application leaderboard only exists on the unfiltered view.
        if ($appKey === null) {
            $urls[] = $this->appsUrl();
        }

        $this->warm($urls);

        if ($appKey !== null) {
            return [];
        }

        return $this->safeReportedKeys($this->apps());
    }

    /**
     * The usable keys of a Sentinel-reported key list. A key that would be unsafe in a request
     * url is dropped (and logged) on its own, so it doesn't fail the whole server's analytics.
     *
     * @param  array<int, mixed>  $keys
     * @return array<int, string>
     */
    private function safeReportedKeys(array $keys): array
    {
        $keys = array_values(array_filter($keys, fn ($key) => is_string($key) && $key !== ''));
        $safe = array_values(array_filter($keys, fn (string $key) => $this->isSafeKey($key)));

        if (count($safe) !== count($keys)) {
            Log::warning('Traffic analytics skipped unsafe app keys reported by Sentinel', [
                'server' => $this->server->uuid,
                'skipped' => count($keys) - count($safe),
            ]);
        }

        return $safe;
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
        $absenceKey = $this->dashboardAbsenceKey();
        if (Cache::get($absenceKey) === true) {
            return null;
        }

        $bundle = $this->fetchBundle($this->dashboardUrl($appKey, $from, $to, $range, $pathLimit, $breakdownLimit, $appsLimit));
        if ($bundle === null) {
            Cache::put($absenceKey, true, 60);
        }

        return $bundle;
    }

    /**
     * Fetch one dashboard bundle, or null when the route is absent or the response is not a
     * real bundle (no `overview` member).
     *
     * @return array<string, mixed>|null
     */
    private function fetchBundle(string $url): ?array
    {
        try {
            $decoded = json_decode($this->raw($url), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) && array_key_exists('overview', $decoded) ? $decoded : null;
    }

    /**
     * The decoded overview at $url, or null when the route is absent or the body is not a
     * real overview. Sentinel always sends `requests`, also for an empty range, so a stub
     * (`{}`) does not count as support.
     *
     * @return array<string, mixed>|null
     */
    private function genuineOverview(string $url): ?array
    {
        try {
            $decoded = json_decode($this->raw($url), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) && array_key_exists('requests', $decoded) ? $decoded : null;
    }

    private function markResourceScopeAbsent(): void
    {
        Cache::put($this->resourceScopeAbsenceKey(), true, self::RESOURCE_SCOPE_ABSENCE_TTL);
    }

    private function resourceScopeAbsenceKey(): string
    {
        return 'traffic:resource-scope-absent:'.$this->server->uuid;
    }

    private function fetchOverview(string $url): TrafficOverviewData
    {
        return TrafficOverviewData::fromSentinel(json_decode($this->raw($url), true) ?? []);
    }

    private function fetchPaths(string $url): Collection
    {
        $rows = json_decode($this->raw($url), true) ?? [];

        return collect($rows)->map(fn ($r) => TrafficPathData::fromSentinel($r));
    }

    private function fetchBreakdown(string $url): Collection
    {
        $rows = json_decode($this->raw($url), true) ?? [];

        return collect($rows)->map(fn ($r) => TrafficBreakdownData::fromSentinel($r));
    }

    /**
     * @return Collection<int, TrafficSeriesBucketData>
     */
    private function fetchSeries(string $url): Collection
    {
        $rows = json_decode($this->raw($url), true);

        if (! is_array($rows) || $rows === []) {
            return collect();
        }

        return collect($rows)->map(fn ($r) => TrafficSeriesBucketData::fromSentinel($r));
    }

    /**
     * Decompose the aggregate bundle back into the per-endpoint response cache, so the
     * existing per-call methods (overview/paths/breakdown/series/attribution and each
     * leaderboard app's overview) read it as a cache hit — the whole page from one fetch.
     *
     * @param  array<int, string>  $dimensions
     * @param  array<string, mixed>  $bundle
     */
    private function seedFromDashboard(?string $appKey, string $from, string $to, string $range, array $dimensions, int $pathLimit, int $breakdownLimit, array $bundle, string $scope = self::SCOPE_APP): void
    {
        $put = fn (string $url, $member) => Cache::put($this->cacheKey($url), json_encode($member), 60);

        $put($this->overviewUrl($appKey, $from, $to, $scope), $bundle['overview'] ?? []);
        $put($this->pathsUrl($appKey, $from, $to, $pathLimit, $scope), $bundle['paths'] ?? []);
        $put($this->seriesUrl($appKey, $range, $scope), $bundle['series'] ?? []);
        $put($this->attributionUrl(), ['attribution' => $bundle['attribution'] ?? null]);

        $breakdowns = $bundle['breakdowns'] ?? [];
        foreach ($dimensions as $dimension) {
            $put($this->breakdownUrl($appKey, $dimension, $from, $to, $breakdownLimit, $scope), $breakdowns[$dimension] ?? []);
        }

        foreach ($bundle['apps'] ?? [] as $app) {
            $uuid = is_array($app) ? ($app['uuid'] ?? null) : null;
            if (is_string($uuid) && $this->isSafeKey($uuid) && isset($app['overview'])) {
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

    private function dashboardAbsenceKey(): string
    {
        return 'traffic:dashboard-absent:'.$this->server->uuid;
    }

    /**
     * The individual endpoint urls (overview, paths, series, breakdowns) for one key or the
     * whole server, used by the batched fallback on Sentinel builds without the dashboard.
     *
     * @param  array<int, string>  $dimensions
     * @return array<int, string>
     */
    private function endpointUrls(?string $appKey, string $from, string $to, array $dimensions, string $range, int $pathLimit, int $breakdownLimit, string $scope = self::SCOPE_APP): array
    {
        $urls = [
            $this->overviewUrl($appKey, $from, $to, $scope),
            $this->pathsUrl($appKey, $from, $to, $pathLimit, $scope),
            $this->seriesUrl($appKey, $range, $scope),
        ];
        foreach ($dimensions as $dimension) {
            $urls[] = $this->breakdownUrl($appKey, $dimension, $from, $to, $breakdownLimit, $scope);
        }

        return $urls;
    }

    private function overviewUrl(?string $appKey, string $from, string $to, string $scope = self::SCOPE_APP): string
    {
        $path = $this->appScopedPath($appKey, 'overview', $scope);

        return $this->url($path, ['from' => $from, 'to' => $to]);
    }

    private function pathsUrl(?string $appKey, string $from, string $to, int $limit, string $scope = self::SCOPE_APP): string
    {
        $path = $this->appScopedPath($appKey, 'paths', $scope);

        return $this->url($path, ['from' => $from, 'to' => $to, 'limit' => (int) $limit]);
    }

    private function breakdownUrl(?string $appKey, string $dimension, string $from, string $to, int $limit, string $scope = self::SCOPE_APP): string
    {
        $this->assertSafeDimension($dimension);
        $path = $this->appScopedPath($appKey, "breakdown/{$dimension}", $scope);

        return $this->url($path, ['from' => $from, 'to' => $to, 'limit' => (int) $limit]);
    }

    private function seriesUrl(?string $appKey, string $range, string $scope = self::SCOPE_APP): string
    {
        $range = in_array($range, ['24h', '7d', '30d'], true) ? $range : '24h';
        $path = $this->appScopedPath($appKey, 'series', $scope);

        return $this->url($path, ['range' => $range]);
    }

    private function dashboardUrl(?string $appKey, string $from, string $to, string $range, int $pathLimit, int $breakdownLimit, int $appsLimit, string $scope = self::SCOPE_APP): string
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

        return $this->url($this->appScopedPath($appKey, 'dashboard', $scope), $query);
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
     * Build a traffic path, optionally scoped to a single (validated) app key, or with
     * SCOPE_RESOURCE to a resource uuid and all of its `{uuid}-*` keys.
     */
    private function appScopedPath(?string $appKey, string $suffix, string $scope = self::SCOPE_APP): string
    {
        if ($appKey === null) {
            return "/traffic/{$suffix}";
        }
        $this->assertSafeKey($appKey);

        return "/{$scope}/{$appKey}/traffic/{$suffix}";
    }

    /**
     * Reject anything that isn't a bare CUID2/UUID or hostname before it is
     * interpolated into a shell-quoted `docker exec ... curl` command
     * (see remoteFetch()/buildFetchCommand()). No quotes, spaces, slashes, or
     * shell metacharacters.
     */
    private function assertSafeKey(string $value): void
    {
        if (! $this->isSafeKey($value)) {
            throw new \InvalidArgumentException('Invalid traffic analytics app key.');
        }
    }

    private function isSafeKey(string $value): bool
    {
        return $value !== '' && preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) === 1;
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
        $this->ensureRemoteAvailable();
        $token = $this->server->settings->ensureValidSentinelToken();

        // Throw the real SSH/docker error (for example, an unreadable SSH key or a missing
        // container). A literal "null" body comes back empty; guard() rejects the empty string.
        return $this->runRemote($this->buildFetchCommand($token, $url));
    }

    /**
     * @param  array<int, string>  $urls
     */
    protected function batchRemoteFetch(array $urls): string
    {
        $this->ensureRemoteAvailable();
        $token = $this->server->settings->ensureValidSentinelToken();

        return $this->runRemote($this->buildBatchCommand($token, $urls));
    }

    /**
     * Page loads and "Live" polls must not wait on a dead server.
     */
    private function ensureRemoteAvailable(): void
    {
        if (Cache::get($this->unavailableKey()) === true) {
            throw new \RuntimeException('Traffic analytics is temporarily unavailable on this server.');
        }

        if (! $this->server->isFunctional()) {
            throw new \RuntimeException('Server is not reachable.');
        }
    }

    /**
     * Unlike instant_remote_process(), there are no SSH retries; a failure marks the server unavailable.
     */
    private function runRemote(string $command): string
    {
        $commands = $this->server->isNonRoot() ? parseCommandsByLineForSudo(collect([$command]), $this->server) : [$command];

        try {
            $process = Process::timeout(self::REMOTE_TIMEOUT)->run(
                SshMultiplexingHelper::generateSshCommand($this->server, implode("\n", $commands), commandTimeout: self::REMOTE_TIMEOUT)
            );

            if ($process->exitCode() !== 0) {
                excludeCertainErrors($process->errorOutput(), $process->exitCode());
            }
        } catch (\Throwable $e) {
            Cache::put($this->unavailableKey(), true, self::UNAVAILABLE_TTL);

            throw $e;
        }

        $output = trim($process->output());

        return $output === 'null' ? '' : sanitize_utf8_text($output);
    }

    private function unavailableKey(): string
    {
        return 'traffic:unavailable:'.$this->server->uuid;
    }

    /**
     * Build the `docker exec ... curl` command run inside the Sentinel container.
     *
     * The token reaches curl on stdin (`-H @-`) from a heredoc, so it is never a process argument
     * that other users on the server can read in /proc. The URL is double-quoted so the literal `&`
     * between the `from`/`to` (and `limit`) query params is not a shell background operator. The
     * app key and dimension are validated (assertSafeKey/assertSafeDimension) before reaching here,
     * so the URL cannot contain shell metacharacters that break out of the quoting.
     */
    protected function buildFetchCommand(string $token, string $url): string
    {
        return 'docker exec -i coolify-sentinel curl -sS '.self::CURL_TIMEOUT_OPTIONS." -H @- \"{$url}\"".$this->authorizationHeredoc($token);
    }

    /**
     * Build one `docker exec` that curls every URL in order and separates the responses
     * with a 0x1E record separator, so warm() can split them back apart. The container shell
     * reads the script, which holds the token, from stdin; each curl reads the header from stdin.
     * No pipe or `sh -c` is used, so the non-root sudo parser only puts sudo in front of the line.
     *
     * @param  array<int, string>  $urls
     */
    protected function buildBatchCommand(string $token, array $urls): string
    {
        $script = implode("\n", array_map(
            fn ($url) => 'curl -s '.self::CURL_TIMEOUT_OPTIONS." -H @- \"{$url}\"".$this->authorizationHeredoc($token)."\nprintf '\\036'",
            $urls
        ));

        return "docker exec -i coolify-sentinel sh -s <<'COOLIFY_SENTINEL_SCRIPT'\n{$script}\nCOOLIFY_SENTINEL_SCRIPT";
    }

    /**
     * The token only has letters, digits and `._-+/=` (ServerSetting::isValidSentinelToken), so it cannot end the heredoc.
     */
    private function authorizationHeredoc(string $token): string
    {
        return " <<'COOLIFY_SENTINEL_AUTH'\nAuthorization: Bearer {$token}\nCOOLIFY_SENTINEL_AUTH";
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
