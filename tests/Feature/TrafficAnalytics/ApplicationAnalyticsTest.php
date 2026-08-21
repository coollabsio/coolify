<?php

use App\Livewire\Project\Application\Analytics;
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

class FakeAnalyticsTrafficClient extends SentinelTrafficClient
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

function fakeAnalyticsResponses(): array
{
    return [
        '/traffic/overview' => json_encode([
            'requests' => 1000,
            'bytes_in' => 5000,
            'bytes_out' => 25000,
            'status' => ['s2xx' => 900, 's3xx' => 50, 's4xx' => 40, 's5xx' => 10],
            'latency' => ['p50' => 12.5, 'p95' => 45.2, 'p99' => 90.1],
            'unique_visitors' => 320,
        ]),
        '/traffic/paths' => json_encode([
            ['path' => '/', 'app' => 'app-key', 'requests' => 500, 'bytes_out' => 12000, 'p50' => 10.0, 'p95' => 30.0],
        ]),
        '/traffic/breakdown/agent' => json_encode([
            ['value' => 'ClaudeBot', 'requests' => 90, 'bytes_out' => 2000],
        ]),
        '/traffic/breakdown/ip' => json_encode([
            ['value' => '198.51.100.42', 'requests' => 60, 'bytes_out' => 1200],
        ]),
        '/traffic/breakdown/useragent' => json_encode([
            ['value' => 'Mozilla/5.0 (Macintosh) PerAppAgent/2.0', 'requests' => 55, 'bytes_out' => 1100],
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
        '/traffic/series' => json_encode([
            ['bucket' => 1_700_000_000_000, 's2xx' => 40, 's3xx' => 2, 's4xx' => 1, 's5xx' => 0, 'requests' => 43, 'bytes_in' => 1000, 'bytes_out' => 5000, 'unique_visitors' => 12, 'p95' => 30.0],
            ['bucket' => 1_700_003_600_000, 's2xx' => 60, 's3xx' => 3, 's4xx' => 2, 's5xx' => 1, 'requests' => 66, 'bytes_in' => 1500, 'bytes_out' => 8000, 'unique_visitors' => 20, 'p95' => 45.0],
        ]),
        '/traffic/attribution' => json_encode(['attribution' => 'GeoIP data by MaxMind']),
    ];
}

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function makeAnalyticsApplication(Team $team, PrivateKey $privateKey, Environment $environment, bool $enabled): Application
{
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->settings->is_traffic_analytics_enabled = $enabled;
    $server->settings->save();

    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test']);

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
}

it('renders KPIs from a mocked traffic client when analytics is enabled', function () {
    $application = makeAnalyticsApplication($this->team, $this->privateKey, $this->environment, true);

    $fake = new FakeAnalyticsTrafficClient($application->destination->server);
    $fake->responses = fakeAnalyticsResponses();
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    Livewire::test(Analytics::class, ['application' => $application])
        ->assertOk()
        ->assertSee('Requests')
        ->assertSee('1,000')
        ->assertSee('Unique visitors')
        ->assertSee('Error rate')
        ->assertSee('/')
        ->assertSee('United States')
        ->assertSee('GeoIP data by MaxMind');
});

it('loads the per-app status time series when Sentinel exposes the series endpoint', function () {
    $application = makeAnalyticsApplication($this->team, $this->privateKey, $this->environment, true);

    $fake = new FakeAnalyticsTrafficClient($application->destination->server);
    $fake->responses = fakeAnalyticsResponses();
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    Livewire::test(Analytics::class, ['application' => $application])
        ->assertOk()
        ->assertSet('hasSeries', true)
        ->assertSet('series', [
            ['bucket' => 1_700_000_000_000, 's2xx' => 40, 's3xx' => 2, 's4xx' => 1, 's5xx' => 0, 'requests' => 43, 'bytesIn' => 1000, 'bytesOut' => 5000, 'uniqueVisitors' => 12, 'p95' => 30.0],
            ['bucket' => 1_700_003_600_000, 's2xx' => 60, 's3xx' => 3, 's4xx' => 2, 's5xx' => 1, 'requests' => 66, 'bytesIn' => 1500, 'bytesOut' => 8000, 'uniqueVisitors' => 20, 'p95' => 45.0],
        ])
        ->assertDispatched('refreshChartData-application-analytics-status');
});

it('decorates per-app paths with the app domain and surfaces AI agents', function () {
    $application = makeAnalyticsApplication($this->team, $this->privateKey, $this->environment, true);
    $application->update(['fqdn' => 'https://api.example.com']);

    $fake = new FakeAnalyticsTrafficClient($application->destination->server);
    $fake->responses = fakeAnalyticsResponses();
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    $component = Livewire::test(Analytics::class, ['application' => $application])
        ->assertOk()
        ->assertSee('api.example.com')
        ->assertSee('AI agents & bots')
        ->assertSee('ClaudeBot')
        ->assertSee('Top IPs')
        ->assertSee('198.51.100.42')
        ->assertSee('Top user agents')
        ->assertSee('PerAppAgent/2.0');

    expect($component->instance()->topPaths[0]['domain'])->toBe('api.example.com');
});

it('falls back to the donut for the per-app chart when the series endpoint is absent', function () {
    $application = makeAnalyticsApplication($this->team, $this->privateKey, $this->environment, true);

    $responses = fakeAnalyticsResponses();
    unset($responses['/traffic/series']);

    $fake = new FakeAnalyticsTrafficClient($application->destination->server);
    $fake->responses = $responses;
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    Livewire::test(Analytics::class, ['application' => $application])
        ->assertOk()
        ->assertSet('hasSeries', false)
        ->assertSet('series', []);
});

it('shows an empty state when traffic analytics is disabled for the server', function () {
    $application = makeAnalyticsApplication($this->team, $this->privateKey, $this->environment, false);

    Livewire::test(Analytics::class, ['application' => $application])
        ->assertOk()
        ->assertSee('Analytics')
        ->assertDontSee('Unique visitors');
});

it('renders the disabled empty-state without crashing when the application has no destination', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => null,
        'destination_type' => null,
    ]);

    expect($application->destination)->toBeNull();

    Livewire::test(Analytics::class, ['application' => $application])
        ->assertOk()
        ->assertSee('Analytics')
        ->assertDontSee('Server settings');
});
