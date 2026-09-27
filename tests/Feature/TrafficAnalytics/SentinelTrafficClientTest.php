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

it('serves every endpoint from one aggregate dashboard fetch when Sentinel exposes it', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    $bundle = json_encode([
        'overview' => ['requests' => 7],
        'paths' => [['path' => '/', 'requests' => 7]],
        'breakdowns' => ['country' => [['value' => 'US', 'requests' => 7]], 'browser' => []],
        'series' => [],
        'attribution' => 'MaxMind',
        'apps' => [
            ['uuid' => 'app-a', 'overview' => ['requests' => 4]],
            ['uuid' => 'app-b', 'overview' => ['requests' => 3]],
        ],
    ]);

    // Only the /traffic/dashboard fetch is allowed; any individual/batch fetch means the
    // bundle wasn't decomposed into the per-endpoint cache.
    $client = new class($server, $bundle) extends SentinelTrafficClient
    {
        public function __construct($server, private string $bundle)
        {
            parent::__construct($server);
        }

        protected function remoteFetch(string $url): string
        {
            if (str_contains($url, '/traffic/dashboard')) {
                return $this->bundle;
            }
            throw new RuntimeException("unexpected individual fetch: {$url}");
        }

        protected function batchRemoteFetch(array $urls): string
        {
            throw new RuntimeException('batch fallback should not run when the dashboard is available');
        }
    };

    $apps = $client->prefetchServerWide(null, 'F', 'T', ['country', 'browser'], '24h');
    expect($apps)->toBe(['app-a', 'app-b']);

    // All served from the seeded cache — remoteFetch throws for anything but the dashboard.
    expect($client->overview(null, 'F', 'T')->requests)->toBe(7)
        ->and($client->overview('app-a', 'F', 'T')->requests)->toBe(4)
        ->and($client->attribution())->toBe('MaxMind');
    $client->paths(null, 'F', 'T');
    $client->breakdown(null, 'country', 'F', 'T');
});

it('warms every server-wide endpoint in a single batched exec and per-call methods hit cache', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    // Batches responses; individual remoteFetch() must never run once the batch has warmed
    // the cache, proving the round-trips collapsed into one exec.
    $client = new class($server) extends SentinelTrafficClient
    {
        public array $batchedCalls = [];

        protected function batchRemoteFetch(array $urls): string
        {
            $this->batchedCalls[] = $urls;

            // One framed body per url, matched by endpoint so ordering stays irrelevant.
            $bodies = array_map(fn ($url) => match (true) {
                str_contains($url, '/traffic/apps') => json_encode(['app-a', 'app-b']),
                str_contains($url, '/attribution') => '{"attribution":"demo"}',
                str_contains($url, '/overview') => '{"requests":1}',
                default => '[]', // paths, series, breakdowns
            }, $urls);

            return implode("\x1e", $bodies)."\x1e";
        }

        protected function remoteFetch(string $url): string
        {
            throw new RuntimeException("individual fetch should not run for: {$url}");
        }
    };

    $apps = $client->prefetchServerWide(null, 'F', 'T', ['country', 'browser'], '24h');

    expect($client->batchedCalls)->toHaveCount(1)
        ->and($apps)->toBe(['app-a', 'app-b']);

    // These now read from the warmed cache; remoteFetch() would throw if they didn't.
    expect($client->overview(null, 'F', 'T')->requests)->toBe(1);
    $client->breakdown(null, 'country', 'F', 'T');
    $client->series(null, '24h');
    $client->attribution();
});

it('probes the absent dashboard route only once per cache window, then reuses the batch fallback', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    // Older Sentinel: the dashboard route 404s (unparseable body), so raw() throws and the
    // client falls back to the batch. The absence must be remembered so a second prefetch in
    // the same window doesn't re-probe the dashboard over SSH.
    $client = new class($server) extends SentinelTrafficClient
    {
        public int $dashboardProbes = 0;

        public int $batchCalls = 0;

        protected function remoteFetch(string $url): string
        {
            if (str_contains($url, '/traffic/dashboard')) {
                $this->dashboardProbes++;

                return 'Not Found';
            }
            throw new RuntimeException("unexpected individual fetch: {$url}");
        }

        protected function batchRemoteFetch(array $urls): string
        {
            $this->batchCalls++;
            $bodies = array_map(fn ($url) => match (true) {
                str_contains($url, '/traffic/apps') => json_encode(['app-a']),
                str_contains($url, '/attribution') => '{"attribution":"demo"}',
                str_contains($url, '/overview') => '{"requests":1}',
                default => '[]',
            }, $urls);

            return implode("\x1e", $bodies)."\x1e";
        }
    };

    $client->prefetchServerWide(null, 'F', 'T', ['country'], '24h');
    $client->prefetchServerWide(null, 'F', 'T', ['country'], '24h');

    expect($client->dashboardProbes)->toBe(1)
        ->and($client->batchCalls)->toBe(1);
});

it('double-quotes the url in the remote curl command so & is not a shell background operator', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $client = new class($server) extends SentinelTrafficClient
    {
        public function exposeCommand(string $token, string $url): string
        {
            return $this->buildFetchCommand($token, $url);
        }
    };

    // A real overview/paths/breakdown URL carries both from and to, joined by `&`.
    $url = 'http://localhost:8888/api/traffic/overview?from=2026-08-01T00:00:00Z&to=2026-08-02T00:00:00Z';
    $command = $client->exposeCommand('tok-123', $url);

    // The URL must be wrapped in double quotes inside the inner `sh -c`, otherwise the
    // container shell backgrounds curl at the `&` and only `from=...` reaches Sentinel.
    expect($command)->toContain('"'.$url.'"');
});
