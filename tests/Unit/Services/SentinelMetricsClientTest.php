<?php

use App\Models\Server;
use App\Services\SentinelMetricsClient;
use Illuminate\Support\Carbon;

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
                'network' => ['rxBytesPerSec' => 4.0, 'txBytesPerSec' => 1.0],
                'status' => ['state' => 'running', 'health' => 'healthy', 'restartCount' => 0]],
        ]),
    ];

    $rows = $client->containersCurrent();

    expect($rows[0]['id'])->toBe('app-a');
    expect($rows[0]['cpu'])->toBe(12.5);
    expect($rows[0]['memUsed'])->toBe(500);
    expect($rows[0]['diskBytes'])->toBe(10); // writableLayer + volumesTotal
    expect($rows[0]['net'])->toBe(5.0);      // rx + tx
});

it('lists only running containers, not removed or stopped ones', function () {
    $client = fakeMetricsClient();
    $client->bodies = [
        '/containers/current' => json_encode([
            ['id' => 'app-running', 'cpu' => ['percent' => '1.0'], 'status' => ['state' => 'running']],
            // Sentinel keeps the last samples of a removed container until retention, without a status.
            ['id' => 'volume-backup-removed', 'cpu' => ['percent' => '79.0'], 'status' => null],
            ['id' => 'exciting_mclaren', 'cpu' => ['percent' => '1.0'], 'status' => ['state' => 'exited']],
        ]),
    ];

    expect(array_column($client->containersCurrent(), 'id'))->toBe(['app-running']);
});

it('returns an empty container list when the endpoint 404s', function () {
    $client = fakeMetricsClient();
    $client->failing = ['/containers/current'];

    expect($client->containersCurrent())->toBe([]);
});

it('averages cpu history into range-aligned buckets', function () {
    $client = fakeMetricsClient();
    $client->bodies = [
        '/cpu/history' => json_encode([
            ['time' => '1700000000000', 'percent' => '10'],
            ['time' => '1700000060000', 'percent' => '20'],
            ['time' => '1700000300000', 'percent' => '40'],
        ]),
    ];

    // 24h range → 5-minute buckets floored to absolute epoch boundaries.
    expect($client->history('cpu', '24h'))->toBe([[1699999800000, 15.0], [1700000100000, 40.0]]);
});

it('aligns buckets across servers whose samples have different timestamps', function () {
    $a = fakeMetricsClient();
    $a->bodies = ['/cpu/history' => json_encode([['time' => 1700000001234, 'percent' => '10']])];
    $b = fakeMetricsClient();
    $b->bodies = ['/cpu/history' => json_encode([['time' => 1700000047890, 'percent' => '30']])];

    expect(array_column($a->history('cpu', '24h'), 0))->toBe(array_column($b->history('cpu', '24h'), 0));
});

it('keeps long history series small', function () {
    $rows = [];
    // 24h of raw 20-second samples.
    for ($i = 0; $i < 4320; $i++) {
        $rows[] = ['time' => 1700000000000 + $i * 20_000, 'percent' => (string) ($i % 100)];
    }

    $client = fakeMetricsClient();
    $client->bodies = ['/cpu/history' => json_encode($rows)];

    expect(count($client->history('cpu', '24h')))->toBeLessThanOrEqual(300);
});

it('keeps only the root mount in disk history', function () {
    $client = fakeMetricsClient();
    $client->bodies = [
        '/disk/history' => json_encode([
            ['time' => 1700000000000, 'mount' => '/', 'usedPercent' => 85],
            ['time' => 1700000000000, 'mount' => '/boot', 'usedPercent' => 20],
        ]),
    ];

    expect($client->history('disk', '24h'))->toBe([[1699999800000, 85.0]]);
});

it('rounds the range start to the minute so history responses are cacheable', function () {
    Carbon::setTestNow('2026-01-01 12:30:05');
    $first = SentinelMetricsClient::rangeFrom('24h');

    Carbon::setTestNow('2026-01-01 12:30:45');

    expect(SentinelMetricsClient::rangeFrom('24h'))->toBe($first)->toBe('2025-12-31T12:30:00Z');

    Carbon::setTestNow();
});

it('does not substitute another mount when root disk history is absent', function () {
    $client = fakeMetricsClient();
    $client->bodies = ['/disk/history' => json_encode([
        ['time' => 1700000000000, 'mount' => '/boot', 'usedPercent' => 90],
    ])];

    expect($client->history('disk', '24h'))->toBe([]);
});

it('supports disk history without mount fields', function () {
    $client = fakeMetricsClient();
    $client->bodies = ['/disk/history' => json_encode([
        ['time' => 1700000000000, 'usedPercent' => 40],
    ])];

    expect($client->history('disk', '24h'))->toBe([[1699999800000, 40.0]]);
});

it('does not substitute another disk mount in the summary', function () {
    $client = fakeMetricsClient();
    $client->bodies = ['/summary' => json_encode(['disk' => [
        ['mount' => '/boot', 'used' => 90, 'total' => 100, 'usedPercent' => 90],
    ]])];

    expect($client->summary()['diskPercent'])->toBeNull();
});

it('uses the Sentinel data mount as the root disk when no root mount is reported', function () {
    $client = fakeMetricsClient();
    $client->bodies = [
        '/summary' => json_encode(['disk' => [
            ['mount' => '/app/db', 'used' => 30, 'total' => 100, 'usedPercent' => 30],
        ]]),
        '/disk/history' => json_encode([
            ['time' => 1700000000000, 'mount' => '/app/db', 'usedPercent' => 30],
            ['time' => 1700000000000, 'mount' => '/boot', 'usedPercent' => 90],
        ]),
    ];

    expect($client->summary()['diskPercent'])->toBe(30.0)
        ->and($client->history('disk', '24h'))->toBe([[1699999800000, 30.0]]);
});

it('prefers the root mount over the Sentinel data mount', function () {
    $client = fakeMetricsClient();
    $client->bodies = ['/disk/history' => json_encode([
        ['time' => 1700000000000, 'mount' => '/app/db', 'usedPercent' => 30],
        ['time' => 1700000000000, 'mount' => '/', 'usedPercent' => 60],
    ])];

    expect($client->history('disk', '24h'))->toBe([[1699999800000, 60.0]]);
});

it('uses the host mount of the Coolify data directory when it is on its own disk', function (string $dataMount) {
    $client = fakeMetricsClient();
    $client->bodies = [
        '/summary' => json_encode(['disk' => [
            ['mount' => $dataMount, 'used' => 20, 'total' => 100, 'usedPercent' => 20],
            ['mount' => '/boot', 'used' => 90, 'total' => 100, 'usedPercent' => 90],
        ]]),
        '/disk/history' => json_encode([
            ['time' => 1700000000000, 'mount' => $dataMount, 'usedPercent' => 20],
            ['time' => 1700000000000, 'mount' => '/boot', 'usedPercent' => 90],
        ]),
    ];

    expect($client->summary()['diskPercent'])->toBe(20.0)
        ->and($client->history('disk', '24h'))->toBe([[1699999800000, 20.0]]);
})->with(['/data', '/data/coolify']);

it('prefers the root mount over the host mount of the Coolify data directory', function () {
    $client = fakeMetricsClient();
    $client->bodies = ['/disk/history' => json_encode([
        ['time' => 1700000000000, 'mount' => '/data', 'usedPercent' => 20],
        ['time' => 1700000000000, 'mount' => '/', 'usedPercent' => 60],
    ])];

    expect($client->history('disk', '24h'))->toBe([[1699999800000, 60.0]]);
});
