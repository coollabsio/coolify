<?php

use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class FakeBrowserSortTrafficClient extends SentinelTrafficClient
{
    protected function raw(string $url): string
    {
        // Twelve paths: /p01 has the fewest requests and /p12 the most, so the
        // first page (10 rows) by requests hides /p01 and /p02.
        $paths = [];
        foreach (range(1, 12) as $i) {
            $paths[] = [
                'path' => sprintf('/p%02d', $i), 'app' => 'app-1', 'requests' => $i * 10, 'bytes_out' => 1000,
                's4xx' => 0, 's5xx' => 0, 'p50' => 10, 'p95' => 30,
            ];
        }

        $responses = [
            '/traffic/apps' => [],
            '/traffic/overview' => [
                'requests' => 780, 'bytes_in' => 5000, 'bytes_out' => 12000,
                'status' => ['s2xx' => 780, 's3xx' => 0, 's4xx' => 0, 's5xx' => 0],
                'latency' => ['p50' => 12.5, 'p95' => 45.2, 'p99' => 90.1],
                'unique_visitors' => 320,
            ],
            '/traffic/paths' => $paths,
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

    app()->bind(SentinelTrafficClient::class, fn () => new FakeBrowserSortTrafficClient($server));
});

it('sorts the top paths table when a column header is selected', function () {
    loginAndSkipBoarding();

    $page = visit('/analytics');

    // Default: requests, largest first. The two smallest are on page 2.
    $page->assertSee('/p12')
        ->assertDontSee('/p01')
        ->click('#analytics-paths-section button[title="Sort by path"]')
        // Path A to Z: the two last names are on page 2.
        ->assertSee('/p01')
        ->assertDontSee('/p12')
        ->click('#analytics-paths-section button[title="Sort by path"]')
        // Second click reverses the direction.
        ->assertSee('/p12')
        ->assertDontSee('/p01')
        ->click('#analytics-paths-section button:has-text("Next")')
        ->assertSee('/p01')
        ->assertDontSee('/p12')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'analytics-paths-sorted');
});
