<?php

namespace App\Services;

use App\Data\Traffic\TrafficOverviewData;

class TrafficAnalyticsAggregator
{
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
}
