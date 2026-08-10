<?php

use App\Livewire\Dashboard\TrafficAnalytics;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

class FakeDashboardTrafficClient extends SentinelTrafficClient
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

class FailingDashboardTrafficClient extends SentinelTrafficClient
{
    protected function raw(string $url): string
    {
        throw new RuntimeException('Server unreachable');
    }
}

function fakeDashboardTrafficResponses(int $requests = 1000): array
{
    return [
        '/traffic/apps' => json_encode([]),
        '/traffic/overview' => json_encode([
            'requests' => $requests,
            'bytes_in' => 5000,
            'bytes_out' => 25000,
            'status' => ['s2xx' => 900, 's3xx' => 50, 's4xx' => 40, 's5xx' => 10],
            'latency' => ['p50' => 12.5, 'p95' => 45.2, 'p99' => 90.1],
            'unique_visitors' => 320,
        ]),
        '/traffic/breakdown/country' => json_encode([
            ['value' => 'US', 'requests' => 600, 'bytes_out' => 15000],
        ]),
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

it('renders the team traffic summary aggregated across servers with an approximate badge', function () {
    $serverOne = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $serverOne->settings->is_traffic_analytics_enabled = true;
    $serverOne->settings->save();

    $serverTwo = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $serverTwo->settings->is_traffic_analytics_enabled = true;
    $serverTwo->settings->save();

    $fakeOne = new FakeDashboardTrafficClient($serverOne);
    $fakeOne->responses = fakeDashboardTrafficResponses(1000);

    $fakeTwo = new FakeDashboardTrafficClient($serverTwo);
    $fakeTwo->responses = fakeDashboardTrafficResponses(500);

    app()->bind(SentinelTrafficClient::class, function ($app, $params) use ($serverOne, $fakeOne, $fakeTwo) {
        $server = $params['server'] ?? null;

        return $server && $server->is($serverOne) ? $fakeOne : $fakeTwo;
    });

    Livewire::test(TrafficAnalytics::class)
        ->assertOk()
        ->assertSee('Requests')
        ->assertSee('1,500')
        ->assertSee('Unique visitors')
        ->assertSee('approximate');
});

it('shows a failure empty-state instead of an all-zero KPI panel when every server fetch fails', function () {
    $serverOne = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $serverOne->settings->is_traffic_analytics_enabled = true;
    $serverOne->settings->save();

    $serverTwo = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $serverTwo->settings->is_traffic_analytics_enabled = true;
    $serverTwo->settings->save();

    app()->bind(SentinelTrafficClient::class, function ($app, $params) {
        return new FailingDashboardTrafficClient($params['server']);
    });

    Livewire::test(TrafficAnalytics::class)
        ->assertOk()
        ->assertSee('No analytics data yet')
        ->assertDontSee('Unique visitors')
        ->assertDontSee('Error rate');
});

it('shows an empty state when no server in the team has traffic analytics enabled', function () {
    Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    Livewire::test(TrafficAnalytics::class)
        ->assertOk()
        ->assertDontSee('Unique visitors')
        ->assertSee('not enabled');
});
