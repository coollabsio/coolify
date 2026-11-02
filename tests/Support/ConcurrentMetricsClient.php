<?php

namespace Tests\Support;

use App\Services\SentinelMetricsClient;

class ConcurrentMetricsClient extends SentinelMetricsClient
{
    public function summary(): ?array
    {
        return [
            'online' => true, 'cpu' => 25.0, 'memUsed' => 100, 'memTotal' => 200,
            'diskUsed' => 10, 'diskTotal' => 100, 'diskPercent' => 10.0,
            'load1' => 0.4, 'netRx' => 5.0, 'netTx' => 1.0,
        ];
    }

    public function containersCurrent(): array
    {
        return [['id' => 'container-'.$this->server->uuid, 'cpu' => 25.0]];
    }

    public function history(string $metric, string $range): array
    {
        if ($metric === 'disk') {
            throw new \RuntimeException('Disk endpoint unavailable.');
        }

        return [[1000, $metric === 'cpu' ? 25.0 : 100.0]];
    }

    public function networkHistory(string $range): array
    {
        return ['rx' => [[1000, 5.0]], 'tx' => [[1000, 1.0]]];
    }
}
