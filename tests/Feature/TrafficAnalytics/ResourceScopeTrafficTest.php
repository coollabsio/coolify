<?php

use App\Livewire\Analytics as GlobalAnalytics;
use App\Livewire\Project\Application\Analytics as ApplicationAnalytics;
use App\Livewire\Project\Service\Analytics as ServiceAnalytics;
use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\SentinelTrafficClient;
use App\Services\TrafficAnalyticsAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Serves canned bodies by the first needle contained in the url (insertion order) and logs
 * every requested url across all instances, so a test can prove which routes were queried.
 */
class FakeResourceScopeTrafficClient extends SentinelTrafficClient
{
    /** @var array<int, string> */
    public static array $requested = [];

    public array $responses = [];

    protected function raw(string $url): string
    {
        self::$requested[] = $url;

        foreach ($this->responses as $needle => $response) {
            if (str_contains($url, $needle)) {
                if ($response instanceof Throwable) {
                    throw $response;
                }

                return $response;
            }
        }

        return '{}';
    }
}

function resourceScopeOverview(int $requests, int $uniques, float $p95 = 20.0): array
{
    return [
        'requests' => $requests,
        'bytes_in' => $requests * 10,
        'bytes_out' => $requests * 100,
        'status' => ['s2xx' => $requests, 's3xx' => 0, 's4xx' => 0, 's5xx' => 0],
        'latency' => ['p50' => 5.0, 'p95' => $p95, 'p99' => 50.0],
        'unique_visitors' => $uniques,
    ];
}

/**
 * Sentinel answers an absent route with 404 and an empty body; raw() then throws.
 */
function resourceRouteAbsent(): RuntimeException
{
    return new RuntimeException('Traffic analytics returned an invalid response.');
}

function bindResourceScopeFake(array $responses): void
{
    app()->bind(SentinelTrafficClient::class, function ($app, $params) use ($responses) {
        $client = new FakeResourceScopeTrafficClient($params['server']);
        $client->responses = $responses;

        return $client;
    });
}

/**
 * @return array<int, string>
 */
function requestedResourceScopeUrls(string $needle): array
{
    return array_values(array_filter(FakeResourceScopeTrafficClient::$requested, fn (string $url) => str_contains($url, $needle)));
}

/**
 * Canned responses for a Sentinel with the resource routes: the resource bundle and the
 * individual resource endpoints. Every per-key route throws, so a per-key query fails the test.
 */
function resourceScopeResponses(string $uuid, array $overview, array $paths = []): array
{
    return [
        "/resource/{$uuid}/traffic/dashboard" => json_encode([
            'overview' => $overview,
            'paths' => $paths,
            'breakdowns' => ['country' => [['value' => 'US', 'requests' => $overview['requests']]]],
            'series' => [],
            'attribution' => null,
        ]),
        "/resource/{$uuid}/traffic/overview" => json_encode($overview),
        "/resource/{$uuid}/traffic/paths" => json_encode($paths),
        "/resource/{$uuid}/traffic/breakdown/country" => json_encode([['value' => 'US', 'requests' => $overview['requests']]]),
        "/resource/{$uuid}/traffic/series" => json_encode([['bucket' => 1000, 's2xx' => $overview['requests'], 'unique_visitors' => $overview['unique_visitors']]]),
        '/app/' => new RuntimeException('per-key route must not be queried when the resource scope exists'),
        '/traffic/apps' => new RuntimeException('the key list must not be queried when the resource scope exists'),
    ];
}

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    Cache::flush();
    Server::flushIdentityMap();
    FakeResourceScopeTrafficClient::$requested = [];

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $this->server->settings->is_traffic_analytics_enabled = true;
    $this->server->settings->save();
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id, 'network' => 'coolify-test']);

    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Storefront']);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'name' => 'Compose Shop',
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'docker_compose_domains' => json_encode([
            'api' => ['domain' => 'https://api.shop.test'],
            'web' => ['domain' => 'https://www.shop.test'],
        ]),
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $this->apiKey = $this->application->uuid.'-'.traefikSafeServiceNameSegment('api');
    $this->webKey = $this->application->uuid.'-'.traefikSafeServiceNameSegment('web');
});

/**
 * Canned responses for an older Sentinel: resource routes are absent, per-key routes answer.
 */
function perKeyFallbackResponses(string $uuid, string $apiKey, string $webKey): array
{
    return [
        '/resource/' => resourceRouteAbsent(),
        '/traffic/apps' => json_encode([$apiKey, $webKey]),
        "/app/{$apiKey}/traffic/overview" => json_encode(resourceScopeOverview(100, 10, 20.0)),
        "/app/{$webKey}/traffic/overview" => json_encode(resourceScopeOverview(50, 8, 40.0)),
        "/app/{$apiKey}/traffic/paths" => json_encode([['path' => '/v1/orders', 'app' => $apiKey, 'requests' => 100]]),
        "/app/{$webKey}/traffic/paths" => json_encode([['path' => '/checkout', 'app' => $webKey, 'requests' => 50]]),
        "/app/{$uuid}/traffic" => new RuntimeException('bare uuid must not be queried when compose keys exist'),
    ];
}

it('builds the url of every resource endpoint', function () {
    $client = new FakeResourceScopeTrafficClient($this->server);
    $client->responses = ['/overview' => json_encode(resourceScopeOverview(1, 1)), '' => '[]'];

    $client->resourceOverview('res-uuid', '2026-08-01T00:00:00Z', '2026-08-02T00:00:00Z');
    $client->resourcePaths('res-uuid', 'F', 'T', 25);
    $client->resourceBreakdown('res-uuid', 'country', 'F', 'T', 10);
    $client->resourceSeries('res-uuid', '7d');
    $client->prefetchResourceScope('res-uuid', 'F', 'T', [], '30d', 25, 10);

    $urls = FakeResourceScopeTrafficClient::$requested;
    expect($urls[0])->toBe('http://localhost:8888/api/resource/res-uuid/traffic/overview?from=2026-08-01T00:00:00Z&to=2026-08-02T00:00:00Z')
        ->and($urls[1])->toBe('http://localhost:8888/api/resource/res-uuid/traffic/paths?from=F&to=T&limit=25')
        ->and($urls[2])->toBe('http://localhost:8888/api/resource/res-uuid/traffic/breakdown/country?from=F&to=T&limit=10')
        ->and($urls[3])->toBe('http://localhost:8888/api/resource/res-uuid/traffic/series?range=7d')
        ->and($urls[4])->toBe('http://localhost:8888/api/resource/res-uuid/traffic/dashboard?from=F&to=T&range=30d&paths_limit=25&breakdown_limit=10');
});

it('rejects an unsafe resource uuid or dimension before any request', function () {
    $client = new FakeResourceScopeTrafficClient($this->server);

    expect(fn () => $client->resourceOverview("x'; touch /tmp/pwned; '", 'F', 'T'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $client->prefetchResourceScope('a b', 'F', 'T', [], '24h'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $client->tryResourceOverview('a/b', 'F', 'T'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $client->resourceBreakdown('res', 'not-a-dimension', 'F', 'T'))->toThrow(InvalidArgumentException::class);
    expect(FakeResourceScopeTrafficClient::$requested)->toBeEmpty();
});

it('serves every resource endpoint from one resource dashboard exec', function () {
    $client = new class($this->server) extends SentinelTrafficClient
    {
        public array $fetched = [];

        protected function remoteFetch(string $url): string
        {
            $this->fetched[] = $url;
            if (str_contains($url, '/resource/res/traffic/dashboard')) {
                return json_encode([
                    'overview' => resourceScopeOverview(9, 4),
                    'paths' => [['path' => '/', 'app' => 'res-web', 'requests' => 9]],
                    'breakdowns' => ['country' => [['value' => 'US', 'requests' => 9]]],
                    'series' => [['bucket' => 1000, 's2xx' => 9]],
                    'attribution' => 'MaxMind',
                ]);
            }
            throw new RuntimeException("unexpected fetch: {$url}");
        }

        protected function batchRemoteFetch(array $urls): string
        {
            throw new RuntimeException('no batch expected');
        }
    };

    expect($client->prefetchResourceScope('res', 'F', 'T', ['country'], '24h'))->toBeTrue()
        ->and($client->resourceOverview('res', 'F', 'T')->uniqueVisitors)->toBe(4)
        ->and($client->resourcePaths('res', 'F', 'T')->first()->toArray()['app'])->toBe('res-web')
        ->and($client->resourceBreakdown('res', 'country', 'F', 'T'))->toHaveCount(1)
        ->and($client->resourceSeries('res', '24h'))->toHaveCount(1)
        ->and($client->attribution())->toBe('MaxMind')
        ->and($client->fetched)->toHaveCount(1);
});

it('falls back to one batch of individual resource endpoints when the resource dashboard is absent', function () {
    $client = new class($this->server) extends SentinelTrafficClient
    {
        public int $batchCalls = 0;

        protected function remoteFetch(string $url): string
        {
            if (str_contains($url, '/traffic/dashboard')) {
                return '';
            }
            throw new RuntimeException("unexpected fetch: {$url}");
        }

        protected function batchRemoteFetch(array $urls): string
        {
            $this->batchCalls++;

            return implode("\x1e", array_map(fn ($url) => match (true) {
                str_contains($url, '/resource/res/traffic/overview') => json_encode(resourceScopeOverview(3, 2)),
                str_contains($url, '/attribution') => '{"attribution":"demo"}',
                str_contains($url, '/resource/res/traffic/') => '[]',
                default => throw new RuntimeException("unexpected batch url: {$url}"),
            }, $urls))."\x1e";
        }
    };

    expect($client->prefetchResourceScope('res', 'F', 'T', ['country'], '24h'))->toBeTrue()
        ->and($client->batchCalls)->toBe(1)
        ->and($client->resourceOverview('res', 'F', 'T')->requests)->toBe(3)
        ->and($client->attribution())->toBe('demo');
    $client->resourceBreakdown('res', 'country', 'F', 'T');
});

it('remembers absent resource routes and does not probe them again within the ttl', function () {
    $client = new class($this->server) extends SentinelTrafficClient
    {
        public int $remoteFetches = 0;

        public int $batchCalls = 0;

        protected function remoteFetch(string $url): string
        {
            $this->remoteFetches++;

            return '';
        }

        protected function batchRemoteFetch(array $urls): string
        {
            $this->batchCalls++;

            return implode("\x1e", array_fill(0, count($urls), ''))."\x1e";
        }
    };

    expect($client->prefetchResourceScope('res', 'F', 'T', ['country'], '24h'))->toBeFalse()
        ->and($client->prefetchResourceScope('res', 'F', 'T', ['country'], '24h'))->toBeFalse()
        ->and($client->tryResourceOverview('res', 'F', 'T'))->toBeNull()
        ->and($client->remoteFetches)->toBe(1)
        ->and($client->batchCalls)->toBe(1)
        ->and($client->supportsResourceScope())->toBeFalse();

    $this->travel(SentinelTrafficClient::RESOURCE_SCOPE_ABSENCE_TTL + 1)->seconds();

    expect($client->supportsResourceScope())->toBeTrue();
});

it('queries an application once in the resource scope, with exact uniques and no approximate badges', function () {
    $paths = [
        ['path' => '/v1/orders', 'app' => $this->apiKey, 'requests' => 100],
        ['path' => '/checkout', 'app' => $this->webKey, 'requests' => 50],
    ];
    bindResourceScopeFake(resourceScopeResponses($this->application->uuid, resourceScopeOverview(150, 12, 30.0), $paths));

    $component = loadLazy(Livewire::test(ApplicationAnalytics::class, ['application' => $this->application]))
        ->assertOk()
        ->assertSet('latencyApproximate', false)
        ->assertSet('uniquesApproximate', false)
        ->assertDontSee('double-counted')
        ->assertDontSee('not a true merged percentile');

    $instance = $component->instance();
    $paths = collect($instance->topPaths)->keyBy('path');
    expect($instance->overview['requests'])->toBe(150)
        ->and($instance->overview['uniqueVisitors'])->toBe(12)
        ->and($instance->overview['latencyP95'])->toBe(30.0)
        ->and($paths['/v1/orders']['domain'])->toBe('api.shop.test')
        ->and($paths['/checkout']['domain'])->toBe('www.shop.test')
        ->and(requestedResourceScopeUrls('/resource/'.$this->application->uuid.'/traffic/overview'))->toHaveCount(1)
        ->and(requestedResourceScopeUrls('/app/'))->toBeEmpty()
        ->and(requestedResourceScopeUrls('/traffic/apps'))->toBeEmpty();
});

it('queries a service in the resource scope with no approximate badges', function () {
    $service = Service::factory()->create([
        'name' => 'Umami',
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
    ]);
    foreach (['web' => 'https://app.svc.test', 'admin' => 'https://admin.svc.test'] as $name => $fqdn) {
        ServiceApplication::create(['service_id' => $service->id, 'name' => $name, 'image' => 'nginx:alpine', 'fqdn' => $fqdn]);
    }
    $adminKey = $service->uuid.'-'.traefikSafeServiceNameSegment('admin');
    bindResourceScopeFake(resourceScopeResponses($service->uuid, resourceScopeOverview(320, 7), [
        ['path' => '/login', 'app' => $adminKey, 'requests' => 20],
    ]));

    $component = loadLazy(Livewire::test(ServiceAnalytics::class, ['service' => $service]))
        ->assertOk()
        ->assertSee('admin.svc.test')
        ->assertSet('latencyApproximate', false)
        ->assertSet('uniquesApproximate', false);

    expect($component->instance()->overview['uniqueVisitors'])->toBe(7)
        ->and(requestedResourceScopeUrls('/app/'))->toBeEmpty();
});

it('falls back to the per-key merge with approximate badges when Sentinel lacks the resource routes', function () {
    bindResourceScopeFake(perKeyFallbackResponses($this->application->uuid, $this->apiKey, $this->webKey));

    $component = loadLazy(Livewire::test(ApplicationAnalytics::class, ['application' => $this->application]))
        ->assertOk()
        ->assertSet('latencyApproximate', true)
        ->assertSet('uniquesApproximate', true)
        ->assertSee('double-counted');

    expect($component->instance()->overview['requests'])->toBe(150)
        ->and($component->instance()->overview['uniqueVisitors'])->toBe(18)
        ->and($component->instance()->overview['latencyP95'])->toBe(40.0);

    $probes = count(requestedResourceScopeUrls('/resource/'));
    expect($probes)->toBeGreaterThan(0);

    // The absence is cached: a refresh within the ttl goes straight to the per-key path.
    $component->call('loadData')->assertSet('uniquesApproximate', true);
    loadLazy(Livewire::test(ApplicationAnalytics::class, ['application' => $this->application]))->assertOk();

    expect(requestedResourceScopeUrls('/resource/'))->toHaveCount($probes);
});

it('uses the resource scope when the global analytics filter selects a resource', function () {
    bindResourceScopeFake([
        ...resourceScopeResponses($this->application->uuid, resourceScopeOverview(150, 12)),
        '/traffic/overview' => json_encode(resourceScopeOverview(999, 99)),
    ]);

    $component = loadLazy(Livewire::withQueryParams(['app' => $this->application->uuid])->test(GlobalAnalytics::class))
        ->assertOk()
        ->assertSet('appUuid', $this->application->uuid)
        ->assertSet('latencyApproximate', false)
        ->assertSet('uniquesApproximate', false);

    expect($component->instance()->overview['requests'])->toBe(150)
        ->and($component->instance()->overview['uniqueVisitors'])->toBe(12)
        ->and(requestedResourceScopeUrls('/app/'))->toBeEmpty()
        ->and(requestedResourceScopeUrls('/traffic/apps'))->toBeEmpty();
});

it('keeps the unfiltered leaderboard on per-key rows without resource queries', function () {
    bindResourceScopeFake([
        '/resource/' => new RuntimeException('the leaderboard must not query the resource scope'),
        '/traffic/apps' => json_encode([$this->apiKey, $this->webKey]),
        "/app/{$this->apiKey}/traffic/overview" => json_encode(resourceScopeOverview(100, 10)),
        "/app/{$this->webKey}/traffic/overview" => json_encode(resourceScopeOverview(50, 8)),
        '/traffic/overview' => json_encode(resourceScopeOverview(150, 15)),
    ]);

    $instance = loadLazy(Livewire::test(GlobalAnalytics::class))->assertOk()->instance();

    expect($instance->topApps[0]['uuid'])->toBe($this->application->uuid)
        ->and($instance->topApps[0]['requests'])->toBe(150)
        ->and($instance->overview['uniqueVisitors'])->toBe(15)
        ->and(requestedResourceScopeUrls('/resource/'))->toBeEmpty();
});

it('keeps a merge of one resource across several servers approximate', function () {
    $otherServer = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);
    $uuid = $this->application->uuid;

    $first = new FakeResourceScopeTrafficClient($this->server);
    $first->responses = resourceScopeResponses($uuid, resourceScopeOverview(100, 10, 20.0));
    $second = new FakeResourceScopeTrafficClient($otherServer);
    $second->responses = resourceScopeResponses($uuid, resourceScopeOverview(50, 5, 40.0));

    $aggregator = new TrafficAnalyticsAggregator(['country']);
    $aggregator->collectResource($first, $uuid, 'F', 'T', '24h', fn () => null);
    $aggregator->collectResource($second, $uuid, 'F', 'T', '24h', fn () => null);

    $result = $aggregator->overview();
    expect($result['overview']->requests)->toBe(150)
        ->and($result['overview']->uniqueVisitors)->toBe(15)
        ->and($result['latencyApproximate'])->toBeTrue()
        ->and($result['uniquesApproximate'])->toBeTrue()
        ->and(requestedResourceScopeUrls('/app/'))->toBeEmpty();

    // One server in the resource scope is exact.
    $single = new TrafficAnalyticsAggregator(['country']);
    $single->collectResource($first, $uuid, 'F', 'T', '24h', fn () => null);
    expect($single->overview()['latencyApproximate'])->toBeFalse()
        ->and($single->overview()['uniquesApproximate'])->toBeFalse();
});
