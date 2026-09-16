<?php

namespace App\Livewire\Concerns;

use App\Services\FleetMetricsAggregator;

trait BuildsMetricsChartPayload
{
    /**
     * @param  array{cpu: array, memory: array, disk: array, load: array, networkRx: array, networkTx: array}  $seriesByServer
     * @return array<string, mixed>
     */
    protected function buildChartPayload(array $seriesByServer): array
    {
        return [
            'range' => $this->range,
            'cpu' => FleetMetricsAggregator::sumSeriesByBucket($seriesByServer['cpu'], 'avg'),
            'memory' => FleetMetricsAggregator::sumSeriesByBucket($seriesByServer['memory'], 'sum'),
            'disk' => FleetMetricsAggregator::sumSeriesByBucket($seriesByServer['disk'], 'avg'),
            'load' => FleetMetricsAggregator::sumSeriesByBucket($seriesByServer['load'], 'avg'),
            'networkRx' => FleetMetricsAggregator::sumSeriesByBucket($seriesByServer['networkRx'], 'sum'),
            'networkTx' => FleetMetricsAggregator::sumSeriesByBucket($seriesByServer['networkTx'], 'sum'),
        ];
    }
}
