<?php

use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class FakeBrowserCityTrafficClient extends SentinelTrafficClient
{
    protected function raw(string $url): string
    {
        $responses = [
            '/traffic/apps' => [],
            '/traffic/overview' => [
                'requests' => 1000, 'bytes_in' => 5000, 'bytes_out' => 25000,
                'status' => ['s2xx' => 900, 's3xx' => 50, 's4xx' => 40, 's5xx' => 10],
                'latency' => ['p50' => 12.5, 'p95' => 45.2, 'p99' => 90.1],
                'unique_visitors' => 320,
            ],
            '/traffic/breakdown/country' => [
                ['value' => 'DE', 'requests' => 600, 'bytes_out' => 15000],
                ['value' => 'FR', 'requests' => 400, 'bytes_out' => 10000],
            ],
            '/traffic/breakdown/city' => [
                ['value' => 'DE|BE|Berlin', 'requests' => 450, 'bytes_out' => 11000, 'lat' => 52.52, 'lon' => 13.405],
                ['value' => 'FR|IDF|Paris', 'requests' => 400, 'bytes_out' => 10000, 'lat' => 48.85, 'lon' => 2.35],
                ['value' => 'DE||', 'requests' => 150, 'bytes_out' => 4000],
            ],
        ];

        foreach ($responses as $needle => $response) {
            if (str_contains($url, $needle)) {
                return json_encode($response);
            }
        }

        return '{}';
    }
}

beforeEach(function () {
    $this->stack = seedBrowserResourceStack();

    $server = $this->stack['server'];
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->save();

    app()->bind(SentinelTrafficClient::class, fn () => new FakeBrowserCityTrafficClient($server));
});

it('switches the Locations card between countries and cities and remembers the selection', function () {
    loginAndSkipBoarding();

    $page = visit('/analytics');

    $page->assertSee('Locations')
        ->assertSee('Germany')
        ->assertDontSee('BE, Germany')
        ->click('button:visible:has-text("Cities")')
        ->assertSee('BE, Germany')
        ->assertSee('IDF, France')
        ->assertDontSee('United States')
        ->refresh()
        ->assertSee('BE, Germany')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'analytics-locations-cities');
});
