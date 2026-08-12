<?php

use App\Data\Traffic\TrafficOverviewData;
use App\Models\Server;
use App\Models\Team;
use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
});

class FakeTrafficClient extends SentinelTrafficClient
{
    public array $captured = [];

    public string $response = '{}';

    protected function raw(string $url): string
    {
        $this->captured[] = $url;

        return $this->response;
    }
}

it('builds per-app overview url and parses response', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $client = new FakeTrafficClient($server);
    $client->response = json_encode([
        'requests' => 3, 'bytes_in' => 1, 'bytes_out' => 2,
        'status' => ['s2xx' => 3, 's3xx' => 0, 's4xx' => 0, 's5xx' => 0],
        'latency' => ['p50' => 1, 'p95' => 2, 'p99' => 3], 'unique_visitors' => 2,
    ]);

    $dto = $client->overview('app-uuid', '2026-08-01T00:00:00Z', '2026-08-02T00:00:00Z');
    expect($dto)->toBeInstanceOf(TrafficOverviewData::class)->and($dto->requests)->toBe(3);
    expect($client->captured[0])->toContain('/api/app/app-uuid/traffic/overview')
        ->toContain('from=2026-08-01T00:00:00Z')->toContain('to=2026-08-02T00:00:00Z');
});

it('builds server-wide overview url when appKey is null', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $client = new FakeTrafficClient($server);
    $client->response = json_encode(['requests' => 0]);
    $client->overview(null, 'a', 'b');
    expect($client->captured[0])->toContain('/api/traffic/overview')->not->toContain('/app/');
});

it('rejects a malicious app key that would break out of the shell quoting', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $client = new FakeTrafficClient($server);

    expect(fn () => $client->overview("x'; touch /tmp/pwned; '", 'a', 'b'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $client->breakdown('foo bar', 'country', 'a', 'b'))
        ->toThrow(InvalidArgumentException::class);
    expect($client->captured)->toBeEmpty();
});

it('rejects a dimension outside the known fixed set', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $client = new FakeTrafficClient($server);

    expect(fn () => $client->breakdown(null, "status'; touch /tmp/pwned; '", 'a', 'b'))
        ->toThrow(InvalidArgumentException::class);
    expect($client->captured)->toBeEmpty();
});

it('accepts a CUID2-like app key and builds the url', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $client = new FakeTrafficClient($server);
    $client->response = json_encode(['requests' => 0]);

    $client->overview('cm2abc123xyz456uuid', 'a', 'b');

    expect($client->captured[0])->toContain('/api/app/cm2abc123xyz456uuid/traffic/overview');
});

it('accepts a hostname-shaped app key and builds the url', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $client = new FakeTrafficClient($server);
    $client->response = json_encode(['requests' => 0]);

    $client->overview('app.example.com', 'a', 'b');

    expect($client->captured[0])->toContain('/api/app/app.example.com/traffic/overview');
});

it('accepts every known dimension and rejects unknown ones', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $client = new FakeTrafficClient($server);
    $client->response = json_encode([]);

    foreach (['status', 'method', 'country', 'referer', 'browser', 'os', 'device', 'protocol', 'scheme', 'tls', 'cache', 'bot'] as $dimension) {
        $client->breakdown(null, $dimension, 'a', 'b');
    }
    expect($client->captured)->toHaveCount(12);

    expect(fn () => $client->breakdown(null, 'not-a-real-dimension', 'a', 'b'))
        ->toThrow(InvalidArgumentException::class);
});

it('builds the server-wide series url with the range knob', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $client = new FakeTrafficClient($server);
    $client->response = json_encode([
        ['bucket' => 1_700_000_000_000, 's2xx' => 5, 's3xx' => 1, 's4xx' => 0, 's5xx' => 0],
    ]);

    $rows = $client->series(null, '7d');

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->bucket)->toBe(1_700_000_000_000)
        ->and($rows->first()->s2xx)->toBe(5);
    expect($client->captured[0])->toContain('/api/traffic/series')
        ->toContain('range=7d')->not->toContain('/app/');
});

it('builds the per-app series url and defaults an unknown range to 24h', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $client = new FakeTrafficClient($server);
    $client->response = json_encode([]);

    $client->series('cm2abc123xyz456uuid', 'bogus');

    expect($client->captured[0])->toContain('/api/app/cm2abc123xyz456uuid/traffic/series')
        ->toContain('range=24h');
});

it('returns an empty series when the endpoint is absent (older Sentinel 404)', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $client = new FakeTrafficClient($server);

    // Empty body / unparseable / empty array all mean "no series" → donut fallback.
    foreach (['', 'Not Found', '{}', '[]'] as $body) {
        $client->response = $body;
        expect($client->series(null, '24h'))->toBeEmpty();
    }
});

it('rejects a malicious app key for the series endpoint', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $client = new FakeTrafficClient($server);

    expect(fn () => $client->series("x'; rm -rf /; '", '24h'))
        ->toThrow(InvalidArgumentException::class);
    expect($client->captured)->toBeEmpty();
});
