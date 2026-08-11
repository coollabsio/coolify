<?php

use App\Livewire\Project\Application\TrafficOverview;
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
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

class FakeAppOverviewTrafficClient extends SentinelTrafficClient
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

// #[Lazy] components render a placeholder first; trigger the deferred mount as the
// browser would via the x-intersect __lazyLoad call, then continue asserting.
function loadLazy(Testable $component): Testable
{
    preg_match('/__lazyLoad\(&#039;([^&]+)&#039;\)/', $component->html(), $matches);

    return $component->call('__lazyLoad', $matches[1]);
}

beforeEach(function () {
    // Servers are memoized via once()/cache; clear both so DB-id reuse across tests doesn't bleed a stale enabled server.
    Cache::flush();
    Server::flushIdentityMap();
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

function makeAppOnServer(bool $analyticsEnabled): Application
{
    $server = Server::factory()->create([
        'team_id' => test()->team->id,
        'private_key_id' => test()->privateKey->id,
    ]);
    $server->settings->is_traffic_analytics_enabled = $analyticsEnabled;
    $server->settings->save();

    $project = Project::factory()->create(['team_id' => test()->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test']);

    return Application::factory()->create([
        'name' => 'Widget App',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
}

it('shows last-24h KPIs and a link to full analytics when enabled with data', function () {
    $application = makeAppOnServer(true);

    app()->bind(SentinelTrafficClient::class, function ($app, $params) {
        $client = new FakeAppOverviewTrafficClient($params['server']);
        $client->responses = [
            '/traffic/overview' => json_encode([
                'requests' => 4200,
                'bytes_in' => 5000,
                'bytes_out' => 25000,
                'status' => ['s2xx' => 4000, 's3xx' => 100, 's4xx' => 80, 's5xx' => 20],
                'latency' => ['p50' => 12.5, 'p95' => 45.2, 'p99' => 90.1],
                'unique_visitors' => 1234,
            ]),
        ];

        return $client;
    });

    $component = loadLazy(Livewire::test(TrafficOverview::class, ['application' => $application]));
    $component->assertOk()
        ->assertSet('enabled', true)
        ->assertSee('Traffic (last 24h)')
        ->assertSee('4,200')
        ->assertSee('1,234')
        ->assertSee('View full analytics');
});

it('shows the muted no-data note when enabled but no traffic recorded', function () {
    $application = makeAppOnServer(true);

    app()->bind(SentinelTrafficClient::class, function ($app, $params) {
        $client = new FakeAppOverviewTrafficClient($params['server']);
        $client->responses = ['/traffic/overview' => json_encode(['requests' => 0])];

        return $client;
    });

    loadLazy(Livewire::test(TrafficOverview::class, ['application' => $application]))
        ->assertOk()
        ->assertSee('No traffic recorded in the last 24h yet');
});

it('shows the enable nudge when analytics is disabled on an eligible server', function () {
    $application = makeAppOnServer(false);

    loadLazy(Livewire::test(TrafficOverview::class, ['application' => $application]))
        ->assertOk()
        ->assertSet('enabled', false)
        ->assertSee('Traffic analytics')
        ->assertSee('Server settings')
        ->assertDontSee('Traffic (last 24h)');
});
