<?php

use App\Models\Server;
use App\Services\SentinelMetricsClient;

class FakeMetricsClient extends SentinelMetricsClient
{
    /** @var array<string, string> substring => body */
    public array $bodies = [];

    /** @var array<int, string> */
    public array $failing = [];

    protected function raw(string $url): string
    {
        foreach ($this->failing as $needle) {
            if (str_contains($url, $needle)) {
                throw new RuntimeException('boom');
            }
        }
        foreach ($this->bodies as $needle => $body) {
            if (str_contains($url, $needle)) {
                return $body;
            }
        }

        return '[]';
    }
}

function fakeMetricsClient(): FakeMetricsClient
{
    $server = Server::factory()->make(['uuid' => 'srv-uuid']);

    return new FakeMetricsClient($server);
}

it('normalizes the host summary, choosing the root disk mount', function () {
    $client = fakeMetricsClient();
    $client->bodies = [
        '/summary' => json_encode([
            'cpu' => ['time' => 't', 'percent' => 42.5],
            'memory' => ['time' => 't', 'total' => 1000, 'used' => 250, 'usedPercent' => 25.0],
            'disk' => [
                ['time' => 't', 'mount' => '/boot', 'total' => 10, 'used' => 9, 'usedPercent' => 90.0],
                ['time' => 't', 'mount' => '/', 'total' => 100, 'used' => 40, 'usedPercent' => 40.0],
            ],
            'network' => ['time' => 't', 'rxBytesPerSec' => 100.0, 'txBytesPerSec' => 20.0],
            'load' => ['time' => 't', 'load1' => 1.5, 'load5' => 1.0, 'load15' => 0.5],
        ]),
    ];

    $summary = $client->summary();

    expect($summary['cpu'])->toBe(42.5);
    expect($summary['memUsed'])->toBe(250);
    expect($summary['diskPercent'])->toBe(40.0); // root mount, not /boot
    expect($summary['netRx'])->toBe(100.0);
    expect($summary['load1'])->toBe(1.5);
});

it('returns null summary when the endpoint fails', function () {
    $client = fakeMetricsClient();
    $client->failing = ['/summary'];

    expect($client->summary())->toBeNull();
});

it('normalizes containers, parsing the stringified cpu percent', function () {
    $client = fakeMetricsClient();
    $client->bodies = [
        '/containers/current' => json_encode([
            ['id' => 'app-a', 'cpu' => ['time' => 't', 'percent' => '12.5'],
                'memory' => ['used' => 500, 'usedPercent' => 5.0],
                'disk' => ['writableLayer' => 3, 'volumesTotal' => 7],
                'network' => ['rxBytesPerSec' => 4.0, 'txBytesPerSec' => 1.0]],
        ]),
    ];

    $rows = $client->containersCurrent();

    expect($rows[0]['id'])->toBe('app-a');
    expect($rows[0]['cpu'])->toBe(12.5);
    expect($rows[0]['memUsed'])->toBe(500);
    expect($rows[0]['diskBytes'])->toBe(10); // writableLayer + volumesTotal
    expect($rows[0]['net'])->toBe(5.0);      // rx + tx
});

it('returns an empty container list when the endpoint 404s', function () {
    $client = fakeMetricsClient();
    $client->failing = ['/containers/current'];

    expect($client->containersCurrent())->toBe([]);
});

it('maps cpu history to [time, value] pairs', function () {
    $client = fakeMetricsClient();
    $client->bodies = [
        '/cpu/history' => json_encode([
            ['time' => 1000, 'percent' => '10'],
            ['time' => 2000, 'percent' => '20'],
        ]),
    ];

    expect($client->history('cpu', 'from'))->toBe([[1000, 10.0], [2000, 20.0]]);
});

it('downsamples long history series so the chart payload stays small', function () {
    $rows = [];
    for ($i = 0; $i < 5000; $i++) {
        $rows[] = ['time' => $i, 'percent' => (string) ($i % 100)];
    }

    $client = fakeMetricsClient();
    $client->bodies = ['/cpu/history' => json_encode($rows)];

    expect(count($client->history('cpu', 'from')))->toBe(300);
});
