<?php

use App\Livewire\Analytics;
use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

class FakeGlobalAnalyticsTrafficClient extends SentinelTrafficClient
{
    public array $responses = [];

    protected function raw(string $url): string
    {
        foreach ($this->responses as $needle => $response) {
            if (str_contains($url, $needle)) {
                return $response;
            }
        }

        return '{}';
    }
}

function fakeGlobalAnalyticsResponses(array $appUuids = []): array
{
    return [
        '/traffic/apps' => json_encode($appUuids),
        '/traffic/overview' => json_encode([
            'requests' => 1000,
            'bytes_in' => 5000,
            'bytes_out' => 25000,
            'status' => ['s2xx' => 900, 's3xx' => 50, 's4xx' => 40, 's5xx' => 10],
            'latency' => ['p50' => 12.5, 'p95' => 45.2, 'p99' => 90.1],
            'unique_visitors' => 320,
        ]),
        '/traffic/paths' => json_encode([
            ['path' => '/', 'app' => $appUuids[0] ?? '', 'requests' => 500, 'bytes_out' => 12000, 'p50' => 10.0, 'p95' => 30.0],
        ]),
        '/traffic/breakdown/agent' => json_encode([
            ['value' => 'GPTBot', 'requests' => 120, 'bytes_out' => 3000],
        ]),
        '/traffic/breakdown/ip' => json_encode([
            ['value' => '203.0.113.7', 'requests' => 80, 'bytes_out' => 2000],
        ]),
        '/traffic/breakdown/useragent' => json_encode([
            ['value' => 'Mozilla/5.0 (X11; Linux x86_64) TestAgent/1.0', 'requests' => 70, 'bytes_out' => 1500],
        ]),
        '/traffic/breakdown/country' => json_encode([
            ['value' => 'US', 'requests' => 600, 'bytes_out' => 15000],
        ]),
        '/traffic/breakdown/referer' => json_encode([
            ['value' => 'google.com', 'requests' => 300, 'bytes_out' => 8000],
        ]),
        '/traffic/breakdown/browser' => json_encode([
            ['value' => 'Chrome', 'requests' => 700, 'bytes_out' => 18000],
        ]),
        '/traffic/breakdown/os' => json_encode([
            ['value' => 'macOS', 'requests' => 400, 'bytes_out' => 10000],
        ]),
        '/traffic/breakdown/device' => json_encode([
            ['value' => 'Desktop', 'requests' => 800, 'bytes_out' => 20000],
        ]),
        '/traffic/breakdown/protocol' => json_encode([
            ['value' => 'HTTP/2', 'requests' => 700, 'bytes_out' => 18000],
            ['value' => 'HTTP/1.1', 'requests' => 300, 'bytes_out' => 8000],
        ]),
        '/traffic/breakdown/cache' => json_encode([
            ['value' => 'hit', 'requests' => 400, 'bytes_out' => 9000],
        ]),
        '/traffic/breakdown/status' => json_encode([
            ['value' => '200', 'requests' => 900, 'bytes_out' => 22000],
        ]),
        '/traffic/series' => json_encode([
            ['bucket' => 1_700_000_000_000, 's2xx' => 40, 's3xx' => 2, 's4xx' => 1, 's5xx' => 0, 'requests' => 43, 'bytes_in' => 1000, 'bytes_out' => 5000, 'unique_visitors' => 12, 'p95' => 30.0],
            ['bucket' => 1_700_003_600_000, 's2xx' => 60, 's3xx' => 3, 's4xx' => 2, 's5xx' => 1, 'requests' => 66, 'bytes_in' => 1500, 'bytes_out' => 8000, 'unique_visitors' => 20, 'p95' => 45.0],
        ]),
        '/traffic/attribution' => json_encode(['attribution' => 'GeoIP data by MaxMind']),
    ];
}

beforeEach(function () {
    Server::flushIdentityMap();
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

function bootEnabledGlobalServer(): Server
{
    $server = Server::factory()->create([
        'team_id' => test()->team->id,
        'private_key_id' => test()->privateKey->id,
    ]);
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->save();

    return $server;
}

it('renders a team-wide analytics summary across enabled servers', function () {
    $server = bootEnabledGlobalServer();

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test']);

    $application = Application::factory()->create([
        'name' => 'Global Leaderboard App',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $fake = new FakeGlobalAnalyticsTrafficClient($server);
    $fake->responses = fakeGlobalAnalyticsResponses([$application->uuid]);
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    Livewire::test(Analytics::class)
        ->assertOk()
        ->assertSee('Analytics')
        ->assertSee('1,000')
        ->assertSee('Top applications')
        ->assertSee('Global Leaderboard App')
        ->assertSee('Top hosts')
        ->assertSee('Top paths')
        ->assertSee('Countries')
        ->assertSee('United States')
        // New breakdown sections surfaced from previously-unused Sentinel dimensions.
        ->assertSee('Requests by device type')
        ->assertSee('Top HTTP versions')
        ->assertSee('HTTP/2')
        ->assertSee('Top cache statuses')
        ->assertSee('Top status codes')
        ->assertSee('GeoIP data by MaxMind')
        ->assertSet('serverOptions', [$server->uuid => $server->name])
        ->assertSet('appOptions', [$application->uuid => 'Global Leaderboard App']);
});

it('shows path domains, links top apps to analytics, groups by project, and surfaces AI agents', function () {
    $server = bootEnabledGlobalServer();

    $project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Storefront']);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test']);

    $application = Application::factory()->create([
        'name' => 'Shop',
        'fqdn' => 'https://shop.example.com',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $fake = new FakeGlobalAnalyticsTrafficClient($server);
    $fake->responses = fakeGlobalAnalyticsResponses([$application->uuid]);
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    $analyticsUrl = route('project.application.analytics', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'application_uuid' => $application->uuid,
    ]);

    $component = Livewire::test(Analytics::class)
        ->assertOk()
        ->assertSee('Top hosts')
        ->assertSee('shop.example.com')          // served host shown in Top hosts + top-app row
        ->assertSee($analyticsUrl, false)         // top-app row links to its analytics page
        ->assertSee('AI agents & bots')
        ->assertSee('GPTBot')
        ->assertSee('Top IPs')
        ->assertSee('203.0.113.7')
        ->assertSee('Top user agents')
        ->assertSee('TestAgent/1.0');

    // Path rows carry the resolved domain, top-app rows carry the domain + analytics link.
    expect($component->instance()->topPaths[0]['domain'])->toBe('shop.example.com');
    expect($component->instance()->topApps[0]['domain'])->toBe('shop.example.com');
    expect($component->instance()->topApps[0]['link'])->toBe($analyticsUrl);

    // The application listbox is grouped under a project header.
    $grouped = $component->instance()->appGroupedOptions;
    expect(collect($grouped)->firstWhere('header', true))->not->toBeNull();
    expect(collect($grouped)->firstWhere('label', 'Storefront')['header'] ?? null)->toBeTrue();
    expect(collect($grouped)->firstWhere('value', $application->uuid)['label'])->toBe('Shop');
});

it('builds a stacked status time series when Sentinel exposes the series endpoint', function () {
    $server = bootEnabledGlobalServer();

    $fake = new FakeGlobalAnalyticsTrafficClient($server);
    $fake->responses = fakeGlobalAnalyticsResponses();
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    Livewire::test(Analytics::class)
        ->assertOk()
        ->assertSet('hasSeries', true)
        ->assertSet('series', [
            ['bucket' => 1_700_000_000_000, 's2xx' => 40, 's3xx' => 2, 's4xx' => 1, 's5xx' => 0, 'requests' => 43, 'bytesIn' => 1000, 'bytesOut' => 5000, 'uniqueVisitors' => 12, 'p95' => 30.0],
            ['bucket' => 1_700_003_600_000, 's2xx' => 60, 's3xx' => 3, 's4xx' => 2, 's5xx' => 1, 'requests' => 66, 'bytesIn' => 1500, 'bytesOut' => 8000, 'uniqueVisitors' => 20, 'p95' => 45.0],
        ])
        ->assertDispatched('refreshChartData-global-analytics-status');
});

it('derives KPI sparklines, device-donut data, and top hosts for the chart payload', function () {
    $server = bootEnabledGlobalServer();

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test']);

    $application = Application::factory()->create([
        'name' => 'Sparkline App',
        'fqdn' => 'https://spark.example.com',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $fake = new FakeGlobalAnalyticsTrafficClient($server);
    $fake->responses = fakeGlobalAnalyticsResponses([$application->uuid]);
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    $instance = Livewire::test(Analytics::class)->assertOk()->instance();

    // Per-bucket sparkline series derived from Sentinel's enriched buckets.
    expect($instance->requestsSpark())->toBe([43, 66]);
    expect($instance->errorsSpark())->toBe([1, 3]);
    expect($instance->bandwidthSpark())->toBe([6000, 9500]);
    expect($instance->uniquesSpark())->toBe([12, 20]);

    // Device breakdown folds into donut labels/series.
    $device = $instance->deviceChartData();
    expect($device['series'])->toBe([800]);

    // Top hosts groups per-app volume by served hostname.
    expect($instance->topHosts[0]['host'])->toBe('spark.example.com');
    expect($instance->topHosts[0]['requests'])->toBe(1000);
});

it('falls back to the donut when Sentinel lacks the series endpoint', function () {
    $server = bootEnabledGlobalServer();

    // Same responses minus the series entry — an older Sentinel returns 404 (empty body).
    $responses = fakeGlobalAnalyticsResponses();
    unset($responses['/traffic/series']);

    $fake = new FakeGlobalAnalyticsTrafficClient($server);
    $fake->responses = $responses;
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    Livewire::test(Analytics::class)
        ->assertOk()
        ->assertSet('hasSeries', false)
        ->assertSet('series', []);
});

it('does not disclose another team application name for a sentinel-reported uuid', function () {
    $server = bootEnabledGlobalServer();

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::factory()->create(['server_id' => $otherServer->id, 'network' => 'other-team-test']);

    $otherTeamApplication = Application::factory()->create([
        'name' => 'Secret Other Team App',
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $fake = new FakeGlobalAnalyticsTrafficClient($server);
    $fake->responses = fakeGlobalAnalyticsResponses([$otherTeamApplication->uuid]);
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    Livewire::test(Analytics::class)
        ->assertOk()
        ->assertDontSee('Secret Other Team App')
        ->assertSee($otherTeamApplication->uuid);
});

it('scopes the view to a single application and hides the leaderboard when filtered by app', function () {
    $server = bootEnabledGlobalServer();

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test']);

    $application = Application::factory()->create([
        'name' => 'Filtered App',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $fake = new FakeGlobalAnalyticsTrafficClient($server);
    $fake->responses = fakeGlobalAnalyticsResponses([$application->uuid]);
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    Livewire::test(Analytics::class)
        ->set('appUuid', $application->uuid)
        ->assertOk()
        ->assertSee('1,000')
        ->assertSee('Top paths')
        ->assertDontSee('Top applications');
});

it('shows the not-enabled empty state when no server has traffic analytics on', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    Livewire::test(Analytics::class)
        ->assertOk()
        ->assertSee('Traffic analytics is not enabled')
        ->assertDontSee('Unique visitors');
});
