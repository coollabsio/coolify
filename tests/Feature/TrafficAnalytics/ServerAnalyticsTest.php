<?php

use App\Livewire\Server\Analytics;
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

class FakeServerAnalyticsTrafficClient extends SentinelTrafficClient
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

function fakeServerAnalyticsResponses(array $appUuids = []): array
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
});

it('renders server-wide analytics with a per-app leaderboard when enabled', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->save();

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test']);

    $application = Application::factory()->create([
        'name' => 'Leaderboard App',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $fake = new FakeServerAnalyticsTrafficClient($server);
    $fake->responses = fakeServerAnalyticsResponses([$application->uuid]);
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    Livewire::test(Analytics::class, ['server_uuid' => $server->uuid])
        ->assertOk()
        ->assertSee('Requests')
        ->assertSee('1,000')
        ->assertSee('Unique visitors')
        ->assertSee('Error rate')
        ->assertSee('/')
        ->assertSee('US')
        ->assertSee('GeoIP data by MaxMind')
        ->assertSee('Leaderboard App');
});

it('does not disclose another team application name for a sentinel-reported uuid', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->save();

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

    $fake = new FakeServerAnalyticsTrafficClient($server);
    $fake->responses = fakeServerAnalyticsResponses([$otherTeamApplication->uuid]);
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    Livewire::test(Analytics::class, ['server_uuid' => $server->uuid])
        ->assertOk()
        ->assertDontSee('Secret Other Team App')
        ->assertSee($otherTeamApplication->uuid);
});

it('shows an empty state when traffic analytics is disabled for the server', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    Livewire::test(Analytics::class, ['server_uuid' => $server->uuid])
        ->assertOk()
        ->assertSee('Analytics')
        ->assertDontSee('Unique visitors');
});
