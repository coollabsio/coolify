<?php

use App\Data\Traffic\TrafficBreakdownData;
use App\Data\Traffic\TrafficOverviewData;
use App\Data\Traffic\TrafficPathData;
use App\Data\Traffic\TrafficSeriesBucketData;
use App\Livewire\Analytics as GlobalAnalytics;
use App\Livewire\Project\Application\Analytics as ApplicationAnalytics;
use App\Livewire\Project\Application\TrafficOverview;
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
use App\Services\TrafficResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Serves canned bodies by the first needle contained in the url, in insertion order, so
 * per-key needles (e.g. "/app/{key}/traffic/overview") must come before generic ones.
 */
class FakeResourceKeysTrafficClient extends SentinelTrafficClient
{
    public array $responses = [];

    protected function raw(string $url): string
    {
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

function resourceKeysOverview(int $requests, int $uniques = 10, float $p95 = 20.0): string
{
    return json_encode([
        'requests' => $requests,
        'bytes_in' => $requests * 10,
        'bytes_out' => $requests * 100,
        'status' => ['s2xx' => $requests, 's3xx' => 0, 's4xx' => 0, 's5xx' => 0],
        'latency' => ['p50' => 5.0, 'p95' => $p95, 'p99' => 50.0],
        'unique_visitors' => $uniques,
    ]);
}

function bindResourceKeysFake(array $responses): void
{
    app()->bind(SentinelTrafficClient::class, function ($app, $params) use ($responses) {
        $client = new FakeResourceKeysTrafficClient($params['server']);
        $client->responses = $responses;

        return $client;
    });
}

beforeEach(function () {
    Cache::flush();
    Server::flushIdentityMap();

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
});

function makeComposeApplication(): Application
{
    return Application::factory()->create([
        'name' => 'Compose Shop',
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'docker_compose_domains' => json_encode([
            'api' => ['domain' => 'https://api.shop.test'],
            'web' => ['domain' => 'https://www.shop.test'],
        ]),
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
}

function makeTrafficService(Server $server, StandaloneDocker $destination, Environment $environment, string $name, string $fqdn): Service
{
    $service = Service::factory()->create([
        'name' => $name,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $environment->id,
    ]);
    ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'web',
        'image' => 'nginx:alpine',
        'fqdn' => $fqdn,
    ]);

    return $service;
}

it('resolves the bare uuid and every uuid-prefixed key, without false prefix matches', function () {
    $client = new FakeResourceKeysTrafficClient($this->server);
    $client->responses = ['/traffic/apps' => json_encode([
        'abc', 'abc-api-1a2b', 'abc-12', 'abcx', 'abcd-web', 'xabc', 'other', 'abc-bad key', 42,
    ])];

    expect($client->resourceKeys('abc'))->toBe(['abc', 'abc-12', 'abc-api-1a2b'])
        ->and($client->resourceKeys('zzz'))->toBe(['zzz'])
        ->and(SentinelTrafficClient::keyBelongsTo('abcx', 'abc'))->toBeFalse()
        ->and(SentinelTrafficClient::keyBelongsTo('abc-web', 'abc'))->toBeTrue()
        ->and(SentinelTrafficClient::candidateOwnerUuids('abc-12-web'))->toBe(['abc-12-web', 'abc-12', 'abc']);

    expect(fn () => $client->resourceKeys("x'; touch /tmp/pwned; '"))->toThrow(InvalidArgumentException::class);
});

it('falls back to the bare uuid when the recorded key list is unavailable', function () {
    $client = new FakeResourceKeysTrafficClient($this->server);
    $client->responses = ['/traffic/apps' => new RuntimeException('unreachable')];

    expect($client->resourceKeys('abc'))->toBe(['abc']);
});

it('warms the key list and every key bundle in two batched execs', function () {
    $client = new class($this->server) extends SentinelTrafficClient
    {
        public array $batches = [];

        protected function batchRemoteFetch(array $urls): string
        {
            $this->batches[] = $urls;

            return implode("\x1e", array_map(fn ($url) => match (true) {
                str_contains($url, '/traffic/apps') => json_encode(['res', 'res-api', 'res-web', 'other']),
                str_contains($url, '/app/res-api/traffic/dashboard') => json_encode(['overview' => ['requests' => 7], 'paths' => [], 'breakdowns' => [], 'series' => [], 'attribution' => null]),
                str_contains($url, '/traffic/dashboard') => json_encode(['overview' => ['requests' => 1], 'paths' => [], 'breakdowns' => [], 'series' => [], 'attribution' => null]),
                default => '[]',
            }, $urls))."\x1e";
        }

        protected function remoteFetch(string $url): string
        {
            throw new RuntimeException("individual fetch should not run for: {$url}");
        }
    };

    $keys = $client->prefetchResource('res', 'F', 'T', ['country'], '24h');

    expect($keys)->toBe(['res', 'res-api', 'res-web'])
        ->and($client->batches)->toHaveCount(2)
        // The bare-uuid bundle came with the key list; only the other two are fetched.
        ->and($client->batches[1])->toHaveCount(2);
    // Served from the seeded cache; remoteFetch() would throw otherwise.
    expect($client->overview('res-api', 'F', 'T')->requests)->toBe(7)
        ->and($client->overview('res-web', 'F', 'T')->requests)->toBe(1);
    $client->breakdown('res-web', 'country', 'F', 'T');
});

it('batches the individual endpoints of every key when Sentinel lacks the dashboard route', function () {
    $client = new class($this->server) extends SentinelTrafficClient
    {
        public int $batchCalls = 0;

        protected function batchRemoteFetch(array $urls): string
        {
            $this->batchCalls++;

            return implode("\x1e", array_map(fn ($url) => match (true) {
                str_contains($url, '/traffic/apps') => json_encode(['res-api', 'res-web']),
                str_contains($url, '/traffic/dashboard') => 'Not Found',
                str_contains($url, '/attribution') => '{"attribution":"demo"}',
                str_contains($url, '/overview') => '{"requests":3}',
                default => '[]',
            }, $urls))."\x1e";
        }

        protected function remoteFetch(string $url): string
        {
            throw new RuntimeException("individual fetch should not run for: {$url}");
        }
    };

    $keys = $client->prefetchResource('res', 'F', 'T', ['country'], '24h');

    expect($keys)->toBe(['res-api', 'res-web'])
        ->and($client->batchCalls)->toBe(3)
        ->and($client->overview('res-api', 'F', 'T')->requests)->toBe(3)
        ->and($client->overview('res-web', 'F', 'T')->requests)->toBe(3)
        ->and($client->attribution())->toBe('demo');
});

it('merges overviews, paths, breakdowns, and series of several keys', function () {
    $aggregator = new TrafficAnalyticsAggregator(['country']);

    $aggregator->addOverview(TrafficOverviewData::fromSentinel(json_decode(resourceKeysOverview(100, 10, 20.0), true)));
    $aggregator->addOverview(TrafficOverviewData::fromSentinel(json_decode(resourceKeysOverview(50, 5, 40.0), true)));

    $domains = ['k-api' => 'api.test', 'k-web' => 'web.test'];
    $domainForKey = fn (string $key) => $domains[$key] ?? null;
    $aggregator->addPaths(collect([
        TrafficPathData::fromSentinel(['path' => '/', 'app' => 'k-api', 'requests' => 10, 'p95' => 5]),
        TrafficPathData::fromSentinel(['path' => '/', 'app' => 'k-api', 'requests' => 5, 'p95' => 9]),
    ]), 'k-api', $domainForKey);
    // Older Sentinel omits `app`: the queried key is the fallback.
    $aggregator->addPaths(collect([TrafficPathData::fromSentinel(['path' => '/', 'requests' => 30])]), 'k-web', $domainForKey);

    $aggregator->addBreakdown('country', collect([TrafficBreakdownData::fromSentinel(['value' => 'US', 'requests' => 4, 'bytes_out' => 1])]));
    $aggregator->addBreakdown('country', collect([TrafficBreakdownData::fromSentinel(['value' => 'US', 'requests' => 6, 'bytes_out' => 2])]));

    $aggregator->addSeries(collect([TrafficSeriesBucketData::fromSentinel(['bucket' => 2000, 's2xx' => 1]), TrafficSeriesBucketData::fromSentinel(['bucket' => 1000, 's2xx' => 2])]));
    $aggregator->addSeries(collect([TrafficSeriesBucketData::fromSentinel(['bucket' => 1000, 's2xx' => 3])]));

    $overview = $aggregator->overview();
    expect($overview['overview']->requests)->toBe(150)
        ->and($overview['overview']->uniqueVisitors)->toBe(15)
        ->and($overview['overview']->latencyP95)->toBe(40.0)
        ->and($overview['latencyApproximate'])->toBeTrue()
        ->and($overview['uniquesApproximate'])->toBeTrue();

    $paths = $aggregator->topPaths();
    expect($paths)->toHaveCount(2)
        ->and($paths[0])->toMatchArray(['path' => '/', 'domain' => 'web.test', 'requests' => 30])
        ->and($paths[1])->toMatchArray(['path' => '/', 'domain' => 'api.test', 'requests' => 15, 'p95' => 9.0]);

    expect($aggregator->breakdowns()['country'])->toBe([['value' => 'US', 'requests' => 10, 'bytesOut' => 3]]);
    expect(array_column($aggregator->series(), 'bucket'))->toBe([1000, 2000])
        ->and($aggregator->series()[0]['s2xx'])->toBe(5);
});

it('shows a compose application with the data of all its compose service keys', function () {
    $application = makeComposeApplication();
    $apiKey = $application->uuid.'-'.traefikSafeServiceNameSegment('api');
    $webKey = $application->uuid.'-'.traefikSafeServiceNameSegment('web');

    bindResourceKeysFake([
        '/traffic/apps' => json_encode([$apiKey, $webKey, 'someone-else']),
        "/app/{$apiKey}/traffic/overview" => resourceKeysOverview(100),
        "/app/{$webKey}/traffic/overview" => resourceKeysOverview(50),
        "/app/{$apiKey}/traffic/paths" => json_encode([['path' => '/v1/orders', 'app' => $apiKey, 'requests' => 100]]),
        "/app/{$webKey}/traffic/paths" => json_encode([['path' => '/checkout', 'app' => $webKey, 'requests' => 50]]),
        "/app/{$application->uuid}/traffic" => new RuntimeException('bare uuid must not be queried when compose keys exist'),
    ]);

    $component = loadLazy(Livewire::test(ApplicationAnalytics::class, ['application' => $application]))
        ->assertOk()
        ->assertSee('150')
        ->assertSee('api.shop.test')
        ->assertSee('www.shop.test')
        ->assertSet('uniquesApproximate', true);

    $paths = collect($component->instance()->topPaths)->keyBy('path');
    expect($component->instance()->overview['requests'])->toBe(150)
        ->and($paths['/v1/orders']['domain'])->toBe('api.shop.test')
        ->and($paths['/checkout']['domain'])->toBe('www.shop.test');
});

it('sums every compose key in the application traffic card', function () {
    $application = makeComposeApplication();
    $apiKey = $application->uuid.'-'.traefikSafeServiceNameSegment('api');
    $webKey = $application->uuid.'-'.traefikSafeServiceNameSegment('web');

    bindResourceKeysFake([
        '/traffic/apps' => json_encode([$apiKey, $webKey]),
        "/app/{$apiKey}/traffic/overview" => resourceKeysOverview(4000),
        "/app/{$webKey}/traffic/overview" => resourceKeysOverview(200),
    ]);

    loadLazy(Livewire::test(TrafficOverview::class, ['application' => $application]))
        ->assertOk()
        ->assertSee('4,200');
});

it('groups compose keys of one application into one leaderboard row', function () {
    $application = makeComposeApplication();
    $apiKey = $application->uuid.'-'.traefikSafeServiceNameSegment('api');
    $webKey = $application->uuid.'-'.traefikSafeServiceNameSegment('web');

    bindResourceKeysFake([
        '/traffic/apps' => json_encode([$apiKey, $webKey, 'orphan-key']),
        "/app/{$apiKey}/traffic/overview" => resourceKeysOverview(100),
        "/app/{$webKey}/traffic/overview" => resourceKeysOverview(50),
        '/app/orphan-key/traffic/overview' => resourceKeysOverview(5),
        '/traffic/overview' => resourceKeysOverview(155),
        '/traffic/paths' => json_encode([['path' => '/v1', 'app' => $apiKey, 'requests' => 9]]),
    ]);

    $instance = loadLazy(Livewire::test(GlobalAnalytics::class))
        ->assertOk()
        ->assertSee('Compose Shop')
        ->assertDontSee($apiKey)
        ->instance();

    $rows = collect($instance->topApps)->keyBy('uuid');
    expect($rows)->toHaveCount(2)
        ->and($rows[$application->uuid]['name'])->toBe('Compose Shop')
        ->and($rows[$application->uuid]['requests'])->toBe(150)
        ->and($rows[$application->uuid]['link'])->toBe(route('project.application.analytics', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $application->uuid,
        ]))
        ->and($rows['orphan-key']['name'])->toBe('orphan-key')
        ->and($rows['orphan-key']['link'])->toBeNull();

    // Hosts stay per served domain; path rows carry the compose service domain.
    $hosts = collect($instance->topHosts)->keyBy('host');
    expect($hosts['api.shop.test']['requests'])->toBe(100)
        ->and($hosts['www.shop.test']['requests'])->toBe(50)
        ->and($instance->topPaths[0]['domain'])->toBe('api.shop.test');
});

it('lists services in the filter, links service rows to service analytics, and queries all service keys', function () {
    $service = makeTrafficService($this->server, $this->destination, $this->environment, 'Umami', 'https://stats.shop.test');
    $serviceKey = $service->uuid.'-'.traefikSafeServiceNameSegment('web');

    bindResourceKeysFake([
        '/traffic/apps' => json_encode([$serviceKey]),
        "/app/{$serviceKey}/traffic/overview" => resourceKeysOverview(70),
        '/traffic/overview' => resourceKeysOverview(70),
    ]);

    $component = loadLazy(Livewire::test(GlobalAnalytics::class))
        ->assertOk()
        ->assertSee('Umami');

    $serviceLink = route('project.service.analytics', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'service_uuid' => $service->uuid,
    ]);
    $row = $component->instance()->topApps[0];
    expect($row['uuid'])->toBe($service->uuid)
        ->and($row['name'])->toBe('Umami')
        ->and($row['isService'])->toBeTrue()
        ->and($row['domain'])->toBe('stats.shop.test')
        ->and($row['link'])->toBe($serviceLink);
    $component->assertSee($serviceLink, false);

    expect(collect($component->instance()->appGroupedOptions)->firstWhere('value', $service->uuid)['label'])->toBe('Umami (Service)');

    $component->set('appUuid', $service->uuid)
        ->assertSet('appUuid', $service->uuid)
        ->assertDontSee('Top applications');
    expect($component->instance()->overview['requests'])->toBe(70);
});

it('never names or links a key owned by another team resource', function () {
    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::factory()->create(['server_id' => $otherServer->id, 'network' => 'other-team-test']);
    $otherService = makeTrafficService($otherServer, $otherDestination, $otherEnvironment, 'Secret Other Service', 'https://secret.other.test');
    $otherKey = $otherService->uuid.'-'.traefikSafeServiceNameSegment('web');

    bindResourceKeysFake([
        '/traffic/apps' => json_encode([$otherKey]),
        '/traffic/overview' => resourceKeysOverview(10),
    ]);

    $component = loadLazy(Livewire::test(GlobalAnalytics::class))
        ->assertOk()
        ->assertDontSee('Secret Other Service')
        ->assertDontSee('secret.other.test')
        ->assertSee($otherKey);

    expect($component->instance()->topApps[0]['link'])->toBeNull()
        ->and($component->instance()->appOptions)->not->toHaveKey($otherService->uuid);

    // A bookmarked filter for another team's service is dropped, not queried.
    loadLazy(Livewire::withQueryParams(['app' => $otherService->uuid])->test(GlobalAnalytics::class))
        ->assertSet('appUuid', '');
});

it('finds the preview domain for normal and compose preview keys', function () {
    $application = Application::factory()->create([
        'fqdn' => 'https://shop.test',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $application->previews()->create([
        'pull_request_id' => 12,
        'pull_request_html_url' => 'https://github.com/coollabsio/shop/pull/12',
        'fqdn' => 'https://12.shop.test',
        'docker_compose_domains' => json_encode(['api' => ['domain' => 'https://api-12.shop.test']]),
    ]);
    $resource = TrafficResource::for($application->fresh());

    expect($resource->domainForKey("{$application->uuid}-pr-12"))->toBe('12.shop.test')
        ->and($resource->domainForKey("{$application->uuid}-12"))->toBe('12.shop.test')
        ->and($resource->domainForKey("{$application->uuid}-12-api"))->toBe('api-12.shop.test')
        ->and($resource->domainForKey($application->uuid))->toBe('shop.test');
});
