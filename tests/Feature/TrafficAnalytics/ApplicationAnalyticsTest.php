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
            ['path' => '/', 'requests' => 500, 'bytes_out' => 12000, 'p50' => 10.0, 'p95' => 30.0],
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
        '/traffic/attribution' => json_encode(['attribution' => 'GeoIP data by MaxMind']),
    ];
}

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
        'team_id' => $this->team->id,
    ]);

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
        ->assertSee('US')
        ->assertSee('GeoIP data by MaxMind');
});

it('shows an empty state when traffic analytics is disabled for the server', function () {
    $application = makeAnalyticsApplication($this->team, $this->privateKey, $this->environment, false);

    Livewire::test(Analytics::class, ['application' => $application])
        ->assertOk()
        ->assertSee('Analytics')
        ->assertDontSee('Unique visitors');
});
