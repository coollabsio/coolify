<?php

use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class FakeBrowserUserAgentTrafficClient extends SentinelTrafficClient
{
    protected function raw(string $url): string
    {
        $responses = [
            '/traffic/apps' => [],
            '/traffic/overview' => [
                'requests' => 900, 'bytes_in' => 5000, 'bytes_out' => 12000,
                'status' => ['s2xx' => 900, 's3xx' => 0, 's4xx' => 0, 's5xx' => 0],
                'latency' => ['p50' => 12.5, 'p95' => 45.2, 'p99' => 90.1],
                'unique_visitors' => 320,
            ],
            '/breakdown/useragent' => [
                ['value' => 'Mozilla/5.0 Firefox/156.0', 'requests' => 654, 'bytes_out' => 1000],
                ['value' => 'buildkit/v0.33', 'requests' => 138, 'bytes_out' => 0],
                ['value' => 'docker/29.6.2 go/go1.26.5 os/linux', 'requests' => 63, 'bytes_out' => 500],
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
    config()->set('app.maintenance.store', 'array');

    $this->stack = seedBrowserResourceStack();

    $server = $this->stack['server'];
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->save();

    app()->bind(SentinelTrafficClient::class, fn () => new FakeBrowserUserAgentTrafficClient($server));
});

/**
 * The names of the visible user agent rows.
 */
function visibleUserAgentNames(mixed $page): array
{
    return $page->script(<<<'JS'
        [...document.querySelectorAll('#analytics-useragent-section [data-row]')]
            .filter((row) => getComputedStyle(row).display !== 'none')
            .map((row) => JSON.parse(row.dataset.row).name)
    JS);
}

it('hides container tooling user agents until the toggle is selected', function () {
    loginAndSkipBoarding();

    $page = visit('/analytics');
    $toggle = '#analytics-useragent-section button[x-text]';

    $page->assertSeeIn($toggle, 'Show 2 container tooling agents');
    expect(visibleUserAgentNames($page))->toBe(['Mozilla/5.0 Firefox/156.0']);

    $page->script("document.querySelector('{$toggle}').click()");
    $page->assertSeeIn($toggle, 'Hide container tooling')
        ->wait(0.5);
    expect(visibleUserAgentNames($page))->toBe([
        'Mozilla/5.0 Firefox/156.0',
        'buildkit/v0.33',
        'docker/29.6.2 go/go1.26.5 os/linux',
    ]);

    $page->script("document.querySelector('{$toggle}').click()");
    $page->assertSeeIn($toggle, 'Show 2 container tooling agents')
        ->wait(0.5)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'analytics-useragent-tooling');
    expect(visibleUserAgentNames($page))->toBe(['Mozilla/5.0 Firefox/156.0']);
});
