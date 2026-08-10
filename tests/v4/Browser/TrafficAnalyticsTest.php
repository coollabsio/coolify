<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // seedBrowserResourceStack() creates a "localhost" server with traffic
    // analytics disabled by default (is_traffic_analytics_enabled defaults
    // to false), so these smoke tests exercise the deterministic, Sentinel-free
    // "disabled" empty-state path across the three analytics surfaces.
    $this->stack = seedBrowserResourceStack();
    $this->application = createBrowserApplication($this->stack, [
        'uuid' => 'app-traffic-analytics',
        'name' => 'Traffic App',
    ]);
});

it('shows the disabled empty state on the application analytics tab', function () {
    loginAndSkipBoarding();

    $url = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    );

    $page = visit("{$url}/analytics");

    $page->assertSee('Traffic App')
        ->assertSee('Traffic analytics is not enabled')
        ->assertSee('Enable Sentinel traffic analytics for this server to start collecting request analytics.')
        ->screenshot(filename: 'application-analytics-disabled-empty-state');
});

it('shows the disabled empty state on the server analytics tab', function () {
    loginAndSkipBoarding();

    $server = $this->stack['server'];

    $page = visit("/server/{$server->uuid}/analytics");

    $page->assertSee('Traffic analytics is not enabled')
        ->assertSee('Enable Sentinel traffic analytics for this server to start collecting request analytics.')
        ->screenshot(filename: 'server-analytics-disabled-empty-state');
});

it('shows the disabled empty state on the dashboard traffic widget', function () {
    $page = loginAndSkipBoarding();

    $page->assertSee('Traffic analytics')
        ->assertSee('Traffic analytics is not enabled')
        ->assertSee('Enable Sentinel traffic analytics on a server to see a team-wide summary here.')
        ->screenshot(filename: 'dashboard-traffic-analytics-disabled-empty-state');
});
