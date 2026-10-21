<?php

namespace App\Livewire\Concerns;

use App\Services\FleetMetricsAggregator;

trait BuildsMetricsChartPayload
{
    /**
     * Sparklines read at a glance, not on close inspection, so they carry far fewer
     * points than the full trend charts (industry guidance is ~10-50 per spark). All
     * KPI-tile sparks share one downsampled axis so their shapes stay comparable.
     */
    private const SPARK_MAX_POINTS = 40;

    /**
     * @param  array{cpu: array, memory: array, disk: array, load: array, networkRx: array, networkTx: array}  $seriesByServer
     * @return array<string, mixed>
     */
    protected function buildChartPayload(array $seriesByServer): array
    {
        $cpu = FleetMetricsAggregator::sumSeriesByBucket($seriesByServer['cpu'], 'avg');
        $memory = FleetMetricsAggregator::sumSeriesByBucket($seriesByServer['memory'], 'sum');
        $disk = FleetMetricsAggregator::sumSeriesByBucket($seriesByServer['disk'], 'avg');
        $load = FleetMetricsAggregator::sumSeriesByBucket($seriesByServer['load'], 'avg');
        $networkRx = FleetMetricsAggregator::sumSeriesByBucket($seriesByServer['networkRx'], 'sum');
        $networkTx = FleetMetricsAggregator::sumSeriesByBucket($seriesByServer['networkTx'], 'sum');

        // Throughput spark = rx + tx per bucket.
        $network = $this->combineSeries($networkRx, $networkTx);
        $categories = $this->sparkCategories($cpu, $memory, $disk, $network);

        [$sparkCategories, $sparks] = $this->capSparks($categories, [
            'cpuSpark' => $this->alignSpark($cpu, $categories),
            'memSpark' => $this->alignSpark($memory, $categories),
            'diskSpark' => $this->alignSpark($disk, $categories),
            'netSpark' => $this->alignSpark($network, $categories),
        ], self::SPARK_MAX_POINTS);

        return [
            'range' => $this->range,
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
            'load' => $load,
            'networkRx' => $networkRx,
            'networkTx' => $networkTx,
            // KPI-tile sparklines: values-only series on one shared, capped time axis.
            'sparkCategories' => $sparkCategories,
        ] + $sparks;
    }

    /**
     * Thin every spark and the shared axis to at most $max points using the same
     * evenly-spaced indices (first and last always kept), so the series stay aligned.
     *
     * @param  array<int, int>  $categories
     * @param  array<string, array<int, float|int|null>>  $sparks
     * @return array{0: array<int, int>, 1: array<string, array<int, float|int|null>>}
     */
    private function capSparks(array $categories, array $sparks, int $max): array
    {
        $count = count($categories);
        if ($count <= $max || $max < 2) {
            return [$categories, $sparks];
        }

        $indices = [];
        for ($i = 0; $i < $max; $i++) {
            $indices[] = (int) round($i * ($count - 1) / ($max - 1));
        }
        $indices = array_values(array_unique($indices));

        $pick = fn (array $values): array => array_values(array_map(fn (int $i) => $values[$i], $indices));

        return [$pick($categories), array_map($pick, $sparks)];
    }

    /**
     * Sum two [ts, value] series bucket-by-bucket into one sorted [ts, value] series.
     *
     * @param  array<int, array{0: int, 1: float}>  $a
     * @param  array<int, array{0: int, 1: float}>  $b
     * @return array<int, array{0: int, 1: float}>
     */
    private function combineSeries(array $a, array $b): array
    {
        $map = [];
        foreach ([$a, $b] as $series) {
            foreach ($series as [$ts, $value]) {
                $map[$ts] = ($map[$ts] ?? 0.0) + (float) $value;
            }
        }
        ksort($map);

        return array_map(fn ($ts, $value) => [(int) $ts, $value], array_keys($map), array_values($map));
    }

    /**
     * Sorted union of bucket timestamps across every series (the shared spark x-axis).
     *
     * @param  array<int, array{0: int, 1: float}>  ...$seriesList
     * @return array<int, int>
     */
    private function sparkCategories(array ...$seriesList): array
    {
        $set = [];
        foreach ($seriesList as $series) {
            foreach ($series as [$ts]) {
                $set[(int) $ts] = true;
            }
        }
        $categories = array_keys($set);
        sort($categories);

        return $categories;
    }

    /**
     * Project a [ts, value] series onto the shared category axis, leaving gaps as null.
     *
     * @param  array<int, array{0: int, 1: float}>  $series
     * @param  array<int, int>  $categories
     * @return array<int, float|int|null>
     */
    private function alignSpark(array $series, array $categories): array
    {
        $map = [];
        foreach ($series as [$ts, $value]) {
            $map[(int) $ts] = $value;
        }

        return array_map(fn ($ts) => $map[$ts] ?? null, $categories);
    }
}
