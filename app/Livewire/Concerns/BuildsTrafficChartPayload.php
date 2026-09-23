<?php

namespace App\Livewire\Concerns;

/**
 * Shared derivations for the traffic chart payload: per-bucket sparkline series,
 * the device-donut labels/series, and the globe's per-country marker data. Consumed
 * by both the global and per-application analytics components, which expose
 * `$series` (status buckets) and `$breakdowns` (dimension rows).
 */
trait BuildsTrafficChartPayload
{
    /**
     * Per-bucket total requests, for the Requests spark. Derived from the status-class
     * counts (every request carries a status class), so it always matches real traffic
     * regardless of whether Sentinel populates the explicit per-bucket `requests` field.
     *
     * @return array<int, int>
     */
    public function requestsSpark(): array
    {
        return array_map(
            fn ($b) => (int) ($b['s2xx'] ?? 0) + (int) ($b['s3xx'] ?? 0) + (int) ($b['s4xx'] ?? 0) + (int) ($b['s5xx'] ?? 0),
            $this->series,
        );
    }

    /**
     * Whether there is plottable request-over-time data for the Requests chart. False when
     * Sentinel returned no series buckets (older builds) or every bucket is empty (no traffic
     * in the range), so the views can render a no-data state instead of a blank chart.
     */
    public function hasRequestSeries(): bool
    {
        return array_sum($this->requestsSpark()) > 0;
    }

    /**
     * Per-bucket error requests (4xx + 5xx), for the Error-rate spark.
     *
     * @return array<int, int>
     */
    public function errorsSpark(): array
    {
        return array_map(
            fn ($b) => (int) ($b['s4xx'] ?? 0) + (int) ($b['s5xx'] ?? 0),
            $this->series,
        );
    }

    /**
     * Per-bucket bandwidth (bytes in + out), for the Bandwidth spark. Empty for
     * older Sentinel builds that don't emit per-bucket byte counts.
     *
     * @return array<int, int>
     */
    public function bandwidthSpark(): array
    {
        return array_map(
            fn ($b) => (int) ($b['bytesIn'] ?? 0) + (int) ($b['bytesOut'] ?? 0),
            $this->series,
        );
    }

    /**
     * Per-bucket unique visitors, for the Visitors spark.
     *
     * @return array<int, int>
     */
    public function uniquesSpark(): array
    {
        return array_map(fn ($b) => (int) ($b['uniqueVisitors'] ?? 0), $this->series);
    }

    /**
     * Per-bucket p95 latency (ms), for the Latency spark.
     *
     * @return array<int, float>
     */
    public function latencySpark(): array
    {
        return array_map(fn ($b) => round((float) ($b['p95'] ?? 0), 1), $this->series);
    }

    /**
     * Per-country marker data for the globe: [{code, requests}] over known ISO-A2 rows.
     *
     * @return array<int, array{code: string, requests: int}>
     */
    protected function geoMarkers(): array
    {
        $out = [];
        foreach (($this->breakdowns['country'] ?? []) as $row) {
            $code = strtoupper((string) ($row['value'] ?? ''));
            $requests = (int) ($row['requests'] ?? 0);
            if (preg_match('/^[A-Z]{2}$/', $code) && $requests > 0) {
                $out[] = ['code' => $code, 'requests' => $requests];
            }
        }

        return $out;
    }

    /**
     * Device-donut data. Raw Sentinel device values are folded into friendly labels
     * (pc → Desktop, smartphone → Mobile, …) and summed, then sorted by volume.
     *
     * @return array{labels: array<int, string>, series: array<int, int>}
     */
    public function deviceChartData(): array
    {
        $totals = [];
        foreach (($this->breakdowns['device'] ?? []) as $row) {
            $value = (string) ($row['value'] ?? '');
            $label = $value === '__other__' ? 'Other' : deviceLabel($value);
            $totals[$label] = ($totals[$label] ?? 0) + (int) ($row['requests'] ?? 0);
        }
        arsort($totals);

        return [
            'labels' => array_keys($totals),
            'series' => array_map('intval', array_values($totals)),
        ];
    }
}
