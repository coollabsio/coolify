<?php

namespace App\Services;

use App\Data\Traffic\TrafficOverviewData;
use App\Enums\TrafficIpMode;
use App\Livewire\Project\Shared\ResourceTrafficAnalytics;
use App\Models\Server;

/**
 * The traffic analytics of one application or service, in the snake case shape of the
 * REST API (ResourceTraffic schema) and the get_traffic_analytics MCP tool.
 */
final class ResourceTrafficReport
{
    /** Breakdowns with visitor data, returned only to callers that can read sensitive data. */
    private const SENSITIVE_DIMENSIONS = ['ip', 'useragent'];

    /**
     * @return array<string, mixed>
     */
    public static function disabled(string $range): array
    {
        return [
            'enabled' => false,
            'range' => $range,
            'overview' => null,
            'latency_approximate' => null,
            'uniques_approximate' => null,
            'series' => null,
            'top_paths' => null,
            'breakdowns' => null,
            'attribution' => null,
        ];
    }

    /**
     * @param  bool  $includeVisitorData  whether to return the ip and useragent breakdowns
     * @return array<string, mixed>
     *
     * @throws \Throwable when Sentinel does not return traffic analytics
     */
    public static function build(TrafficResource $resource, Server $server, string $range, bool $includeVisitorData): array
    {
        $dimensions = TrafficIpMode::forServer($server)->breakdownDimensions(ResourceTrafficAnalytics::BREAKDOWN_DIMENSIONS);
        if (! $includeVisitorData) {
            $dimensions = array_values(array_diff($dimensions, self::SENSITIVE_DIMENSIONS));
        }

        [$from, $to] = SentinelTrafficClient::rangeWindow($range);
        $client = app(SentinelTrafficClient::class, ['server' => $server]);
        $aggregator = new TrafficAnalyticsAggregator($dimensions);
        $aggregator->collectResource($client, $resource->uuid(), $from, $to, $range, fn (string $appKey) => $resource->domainForKey($appKey));
        $result = $aggregator->overview();

        return [
            'enabled' => true,
            'range' => $range,
            'overview' => self::overview($result['overview']),
            'latency_approximate' => $result['latencyApproximate'],
            'uniques_approximate' => $result['uniquesApproximate'],
            'series' => array_map(fn (array $bucket) => [
                'bucket' => $bucket['bucket'],
                's2xx' => $bucket['s2xx'],
                's3xx' => $bucket['s3xx'],
                's4xx' => $bucket['s4xx'],
                's5xx' => $bucket['s5xx'],
                'requests' => $bucket['requests'],
                'bytes_in' => $bucket['bytesIn'],
                'bytes_out' => $bucket['bytesOut'],
                'unique_visitors' => $bucket['uniqueVisitors'],
                'p95' => $bucket['p95'],
            ], $aggregator->series()),
            'top_paths' => array_map(fn (array $path) => [
                'path' => $path['path'],
                'domain' => $path['domain'],
                'requests' => $path['requests'],
                'bytes_out' => $path['bytesOut'],
                's4xx' => $path['s4xx'],
                's5xx' => $path['s5xx'],
                'p95' => $path['p95'],
            ], $aggregator->topPaths()),
            'breakdowns' => array_map(fn (array $rows) => array_map(fn (array $row) => [
                'value' => $row['value'],
                'requests' => $row['requests'],
                'bytes_out' => $row['bytesOut'],
                ...array_intersect_key($row, ['lat' => true, 'lon' => true]),
            ], $rows), $aggregator->breakdowns()),
            'attribution' => $aggregator->attribution(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function overview(TrafficOverviewData $overview): array
    {
        $errors = $overview->s4xx + $overview->s5xx;

        return [
            'requests' => $overview->requests,
            'unique_visitors' => $overview->uniqueVisitors,
            'bytes_in' => $overview->bytesIn,
            'bytes_out' => $overview->bytesOut,
            'error_rate' => $overview->requests === 0 ? 0.0 : round(($errors / $overview->requests) * 100, 2),
            'latency' => [
                'p50' => $overview->latencyP50,
                'p95' => $overview->latencyP95,
                'p99' => $overview->latencyP99,
            ],
            'status' => [
                's2xx' => $overview->s2xx,
                's3xx' => $overview->s3xx,
                's4xx' => $overview->s4xx,
                's5xx' => $overview->s5xx,
            ],
        ];
    }
}
