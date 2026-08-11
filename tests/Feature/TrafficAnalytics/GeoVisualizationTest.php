<?php

use App\Livewire\Server\Analytics;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

class FakeGeoTrafficClient extends SentinelTrafficClient
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

function fakeGeoResponses(array $countryRows): array
{
    return [
        '/traffic/apps' => json_encode([]),
        '/traffic/overview' => json_encode([
            'requests' => 1000,
            'bytes_in' => 5000,
            'bytes_out' => 25000,
            'status' => ['s2xx' => 900, 's3xx' => 50, 's4xx' => 40, 's5xx' => 10],
            'latency' => ['p50' => 12.5, 'p95' => 45.2, 'p99' => 90.1],
            'unique_visitors' => 320,
        ]),
        '/traffic/paths' => json_encode([]),
        '/traffic/breakdown/country' => json_encode($countryRows),
        '/traffic/attribution' => json_encode(['attribution' => 'GeoIP data by MaxMind']),
    ];
}

function bootGeoServer(): Server
{
    Server::flushIdentityMap();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->save();

    return $server;
}

it('renders resolved country names and the choropleth map when country data is present', function () {
    $server = bootGeoServer();

    $fake = new FakeGeoTrafficClient($server);
    $fake->responses = fakeGeoResponses([
        ['value' => 'US', 'requests' => 600, 'bytes_out' => 15000],
        ['value' => 'DE', 'requests' => 200, 'bytes_out' => 6000],
    ]);
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    Livewire::test(Analytics::class, ['server_uuid' => $server->uuid])
        ->assertOk()
        ->assertSee('Countries')
        ->assertSee('United States')
        ->assertSee('Germany')
        ->assertSee('World map of request volume by country')
        ->assertSeeHtml('path#US')
        ->assertSee('GeoIP data by MaxMind');
});

it('collapses unresolvable country codes into a single Unknown row', function () {
    $server = bootGeoServer();

    $fake = new FakeGeoTrafficClient($server);
    $fake->responses = fakeGeoResponses([
        ['value' => 'US', 'requests' => 600, 'bytes_out' => 15000],
        ['value' => '', 'requests' => 50, 'bytes_out' => 500],
        ['value' => 'ZZ', 'requests' => 25, 'bytes_out' => 250],
    ]);
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    Livewire::test(Analytics::class, ['server_uuid' => $server->uuid])
        ->assertOk()
        ->assertSee('United States')
        ->assertSee('Unknown');
});

it('shows a plain no-data state when no country data has been recorded', function () {
    $server = bootGeoServer();

    $fake = new FakeGeoTrafficClient($server);
    $fake->responses = fakeGeoResponses([]);
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    Livewire::test(Analytics::class, ['server_uuid' => $server->uuid])
        ->assertOk()
        ->assertSee('Countries')
        ->assertSee('No country data for the selected range');
});
