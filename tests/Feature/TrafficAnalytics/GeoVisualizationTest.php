<?php

use App\Livewire\Analytics;
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

function fakeGeoResponses(array $countryRows, array $cityRows = []): array
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
        '/traffic/breakdown/city' => json_encode($cityRows),
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

it('renders resolved country names and the interactive globe when country data is present', function () {
    $server = bootGeoServer();

    $fake = new FakeGeoTrafficClient($server);
    $fake->responses = fakeGeoResponses([
        ['value' => 'US', 'requests' => 600, 'bytes_out' => 15000],
        ['value' => 'DE', 'requests' => 200, 'bytes_out' => 6000],
    ]);
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    loadLazy(Livewire::test(Analytics::class))
        ->assertOk()
        ->assertSee('Countries')
        ->assertSee('United States')
        ->assertSee('Germany')
        // The interactive WebGL globe canvas replaces the old inline SVG choropleth.
        ->assertSeeHtml('id="global-analytics-globe"')
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

    loadLazy(Livewire::test(Analytics::class))
        ->assertOk()
        ->assertSee('United States')
        ->assertSee('Unknown');
});

it('shows a plain no-data state when no country data has been recorded', function () {
    $server = bootGeoServer();

    $fake = new FakeGeoTrafficClient($server);
    $fake->responses = fakeGeoResponses([]);
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    loadLazy(Livewire::test(Analytics::class))
        ->assertOk()
        ->assertSee('Locations')
        ->assertSee('No country data for the selected range');
});

it('mounts the globe from the element itself when country data arrives after the first render', function () {
    $server = bootGeoServer();

    $fake = new FakeGeoTrafficClient($server);
    $fake->responses = fakeGeoResponses([]);
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    $component = loadLazy(Livewire::test(Analytics::class))
        ->assertSee('No country data for the selected range');

    $fake->responses = fakeGeoResponses([
        ['value' => 'US', 'requests' => 600, 'bytes_out' => 15000],
    ]);

    // A @script block runs only once per component, so a globe inserted by a later
    // render never mounted. The mount must live on the element (Alpine x-data).
    $component->call('loadData')
        ->assertSeeHtml('id="global-analytics-globe" x-ref="canvas"')
        ->assertSeeHtml('window.mountTrafficGlobe(canvas');
});

it('renders the city list, the switch, and both marker sets when Sentinel returns city data', function () {
    $server = bootGeoServer();

    $fake = new FakeGeoTrafficClient($server);
    $fake->responses = fakeGeoResponses(
        [['value' => 'DE', 'requests' => 600, 'bytes_out' => 15000]],
        [
            ['value' => 'DE|BE|Berlin', 'requests' => 400, 'bytes_out' => 9000, 'lat' => 52.52, 'lon' => 13.405],
            ['value' => 'DE||', 'requests' => 150, 'bytes_out' => 4000],
            ['value' => '__other__', 'requests' => 50, 'bytes_out' => 2000],
        ],
    );
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    loadLazy(Livewire::test(Analytics::class))
        ->assertOk()
        ->assertSeeHtml('aria-label="Location detail"')
        ->assertSee('Germany')
        ->assertSee('Berlin')
        ->assertSee('BE, Germany')
        // Only the row with coordinates turns the globe on hover.
        ->assertSeeHtml('focus([52.52, 13.405])');
});

it('sends the globe one marker set for each view, without markers for unknown cities', function () {
    $server = bootGeoServer();

    $fake = new FakeGeoTrafficClient($server);
    $fake->responses = fakeGeoResponses(
        [['value' => 'DE', 'requests' => 600, 'bytes_out' => 15000]],
        [
            ['value' => 'DE|BE|Berlin', 'requests' => 400, 'bytes_out' => 9000, 'lat' => 52.52, 'lon' => 13.405],
            // An unknown city never gets a marker, also when Sentinel sends coordinates.
            ['value' => 'DE||', 'requests' => 150, 'bytes_out' => 4000, 'lat' => 51.0, 'lon' => 9.0],
        ],
    );
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    loadLazy(Livewire::test(Analytics::class))
        ->call('loadData')
        ->assertDispatched('refreshChartData-global-analytics-status', function (string $event, array $params): bool {
            return data_get($params, '0.geo') === [
                'countries' => [['code' => 'DE', 'requests' => 600]],
                'cities' => [['lat' => 52.52, 'lon' => 13.405, 'requests' => 400]],
            ];
        });
});

it('shows an empty city list and no city markers when Sentinel returns no city data', function (array $cityRows) {
    $server = bootGeoServer();

    $fake = new FakeGeoTrafficClient($server);
    $fake->responses = fakeGeoResponses([
        ['value' => 'DE', 'requests' => 600, 'bytes_out' => 15000],
    ], $cityRows);
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    loadLazy(Livewire::test(Analytics::class))
        ->assertOk()
        ->assertSee('Germany')
        ->assertSee('No city data for the selected range.')
        ->assertDontSeeHtml('focus([')
        ->call('loadData')
        ->assertDispatched('refreshChartData-global-analytics-status', function (string $event, array $params): bool {
            return data_get($params, '0.geo') === [
                'countries' => [['code' => 'DE', 'requests' => 600]],
                'cities' => [],
            ];
        });
})->with([
    'old sentinel' => [[]],
    'country-only database' => [[['value' => 'DE||', 'requests' => 600, 'bytes_out' => 15000]]],
]);

it('escapes a city name that contains quotes or HTML', function () {
    $server = bootGeoServer();

    $fake = new FakeGeoTrafficClient($server);
    $fake->responses = fakeGeoResponses(
        [['value' => 'US', 'requests' => 600, 'bytes_out' => 15000]],
        [['value' => "US|MO|O'Fallon <b>x</b>", 'requests' => 400, 'bytes_out' => 9000, 'lat' => 38.81, 'lon' => -90.7]],
    );
    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    loadLazy(Livewire::test(Analytics::class))
        ->assertOk()
        ->assertSee("O'Fallon <b>x</b>")
        ->assertDontSeeHtml('<b>x</b></span>')
        ->assertSeeHtml('focus([38.81, -90.7])');
});
