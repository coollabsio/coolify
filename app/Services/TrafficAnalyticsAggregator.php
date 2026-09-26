<?php

namespace App\Services;

use App\Data\Traffic\TrafficOverviewData;

/**
 * Merges Sentinel traffic data from several sources (servers, or the several app keys of
 * one resource) into one view. Counters are summed; latency takes the worst value and
 * uniques are summed, so both are flagged approximate once more than one source is merged.
 */
class TrafficAnalyticsAggregator
{
    /** @var array<int, TrafficOverviewData> */
    private array $overviews = [];

    /** @var array<string, array{path: string, domain: ?string, requests: int, bytesOut: int, s4xx: int, s5xx: int, p95: float}> */
    private array $paths = [];

    /** @var array<string, array<string, array{value: string, requests: int, bytesOut: int}>> */
    private array $breakdowns;

    /** @var array<int, array<string, int|float>> */
    private array $series = [];

    private ?string $attribution = null;

    /**
     * @param  array<int, string>  $dimensions
     */
    public function __construct(private array $dimensions = [])
    {
        $this->breakdowns = array_fill_keys($dimensions, []);
    }

    /**
     * @param  array<int, TrafficOverviewData>  $overviews
     * @return array{overview: TrafficOverviewData, latencyApproximate: bool, uniquesApproximate: bool}
     */
    public static function sumOverviews(array $overviews): array
    {
        $multi = count($overviews) > 1;
        $sum = fn (string $prop) => array_sum(array_map(fn ($o) => $o->{$prop}, $overviews));
        $max = fn (string $prop) => empty($overviews) ? 0.0 : max(array_map(fn ($o) => $o->{$prop}, $overviews));

        $overview = new TrafficOverviewData(
            requests: $sum('requests'),
            bytesIn: $sum('bytesIn'),
            bytesOut: $sum('bytesOut'),
            s2xx: $sum('s2xx'),
            s3xx: $sum('s3xx'),
            s4xx: $sum('s4xx'),
            s5xx: $sum('s5xx'),
            latencyP50: (float) $max('latencyP50'),
            latencyP95: (float) $max('latencyP95'),
            latencyP99: (float) $max('latencyP99'),
            uniqueVisitors: $sum('uniqueVisitors'),
        );

        return [
            'overview' => $overview,
            'latencyApproximate' => $multi,
            'uniquesApproximate' => $multi,
        ];
    }

    /**
     * Fetch every shape for one app key (or the whole server when null) and merge it in.
     * The series fetch is isolated: a failure (or an older Sentinel without the endpoint)
     * never discards the other data; an empty series flips the chart to the donut.
     *
     * @param  callable(string): ?string  $domainForKey  resolves a path row's app key to its domain
     */
    public function collect(SentinelTrafficClient $client, ?string $appKey, string $from, string $to, string $range, callable $domainForKey, int $limit = 50): void
    {
        $this->addOverview($client->overview($appKey, $from, $to));
        $this->addPaths($client->paths($appKey, $from, $to, $limit), $appKey, $domainForKey);

        foreach ($this->dimensions as $dimension) {
            $this->addBreakdown($dimension, $client->breakdown($appKey, $dimension, $from, $to, $limit));
        }

        $this->attribution ??= $client->attribution();

        try {
            $this->addSeries($client->series($appKey, $range));
        } catch (\Throwable) {
            // Leave this source out of the series; the donut fallback covers it.
        }
    }

    public function addOverview(TrafficOverviewData $overview): void
    {
        $this->overviews[] = $overview;
    }

    /**
     * Merge path rows keyed by (app, path), so the same path under two apps stays two
     * rows, each with its own domain. Sentinel's per-row `app` wins; older Sentinel omits
     * it, so a key-scoped fetch falls back to the key it queried.
     *
     * @param  iterable<int, mixed>  $paths
     * @param  callable(string): ?string  $domainForKey
     */
    public function addPaths(iterable $paths, ?string $fallbackAppKey, callable $domainForKey): void
    {
        foreach ($paths as $path) {
            $data = $path->toArray();
            $pathStr = (string) ($data['path'] ?? '');
            $appId = (string) ($data['app'] ?? '');
            $resolveId = $appId !== '' ? $appId : ($fallbackAppKey ?? '');
            $key = $resolveId."\n".$pathStr;

            $this->paths[$key] ??= [
                'path' => $pathStr,
                'domain' => $resolveId !== '' ? $domainForKey($resolveId) : null,
                'requests' => 0, 'bytesOut' => 0, 's4xx' => 0, 's5xx' => 0, 'p95' => 0.0,
            ];
            $this->paths[$key]['requests'] += (int) ($data['requests'] ?? 0);
            $this->paths[$key]['bytesOut'] += (int) ($data['bytesOut'] ?? 0);
            $this->paths[$key]['s4xx'] += (int) ($data['s4xx'] ?? 0);
            $this->paths[$key]['s5xx'] += (int) ($data['s5xx'] ?? 0);
            $this->paths[$key]['p95'] = max($this->paths[$key]['p95'], (float) ($data['p95'] ?? 0));
        }
    }

    /**
     * @param  iterable<int, mixed>  $rows
     */
    public function addBreakdown(string $dimension, iterable $rows): void
    {
        foreach ($rows as $row) {
            $data = $row->toArray();
            $value = (string) ($data['value'] ?? '');

            $this->breakdowns[$dimension][$value] ??= ['value' => $value, 'requests' => 0, 'bytesOut' => 0];
            $this->breakdowns[$dimension][$value]['requests'] += (int) ($data['requests'] ?? 0);
            $this->breakdowns[$dimension][$value]['bytesOut'] += (int) ($data['bytesOut'] ?? 0);
        }
    }

    /**
     * Sum status series by bucket. Uniques are summed (approximate); p95 takes the worst bucket.
     *
     * @param  iterable<int, mixed>  $buckets
     */
    public function addSeries(iterable $buckets): void
    {
        foreach ($buckets as $bucket) {
            $data = $bucket->toArray();
            $ts = (int) ($data['bucket'] ?? 0);

            $this->series[$ts] ??= ['bucket' => $ts, 's2xx' => 0, 's3xx' => 0, 's4xx' => 0, 's5xx' => 0, 'requests' => 0, 'bytesIn' => 0, 'bytesOut' => 0, 'uniqueVisitors' => 0, 'p95' => 0.0];
            foreach (['s2xx', 's3xx', 's4xx', 's5xx', 'requests', 'bytesIn', 'bytesOut', 'uniqueVisitors'] as $field) {
                $this->series[$ts][$field] += (int) ($data[$field] ?? 0);
            }
            $this->series[$ts]['p95'] = max($this->series[$ts]['p95'], (float) ($data['p95'] ?? 0));
        }
    }

    public function hasOverview(): bool
    {
        return $this->overviews !== [];
    }

    /**
     * @return array{overview: TrafficOverviewData, latencyApproximate: bool, uniquesApproximate: bool}
     */
    public function overview(): array
    {
        return self::sumOverviews($this->overviews);
    }

    /**
     * @return array<int, array{path: string, domain: ?string, requests: int, bytesOut: int, s4xx: int, s5xx: int, p95: float}>
     */
    public function topPaths(int $limit = 50): array
    {
        return self::topByRequests(array_values($this->paths), $limit);
    }

    /**
     * @return array<string, array<int, array{value: string, requests: int, bytesOut: int}>>
     */
    public function breakdowns(int $limit = 50): array
    {
        $result = [];
        foreach ($this->dimensions as $dimension) {
            $rows = self::topByRequests(array_values($this->breakdowns[$dimension] ?? []), PHP_INT_MAX);
            if ($dimension === 'referer') {
                $rows = groupRefererBreakdownRows($rows);
            }
            $result[$dimension] = array_slice($rows, 0, $limit);
        }

        return $result;
    }

    /**
     * @return array<int, array<string, int|float>>
     */
    public function series(): array
    {
        $series = $this->series;
        ksort($series);

        return array_values($series);
    }

    public function attribution(): ?string
    {
        return $this->attribution;
    }

    /**
     * @template T of array{requests: int}
     *
     * @param  array<int, T>  $rows
     * @return array<int, T>
     */
    public static function topByRequests(array $rows, int $limit = 50): array
    {
        usort($rows, fn ($a, $b) => $b['requests'] <=> $a['requests']);

        return array_slice($rows, 0, $limit);
    }
}
