<?php

use App\Livewire\Analytics;
use App\Livewire\Server\Analytics\Show;
use App\Livewire\Server\TrafficAnalyticsSettings;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->first();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('registers a server analytics page in the server sidebar', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    expect(route('server.analytics', ['server_uuid' => $server->uuid]))
        ->toEndWith("/server/{$server->uuid}/analytics");

    $sidebar = file_get_contents(resource_path('views/components/server/sidebar.blade.php'));

    expect($sidebar)
        ->toContain("'label' => 'Analytics'")
        ->toContain("'route' => 'server.analytics'");
});

it('prevents access to another teams server analytics page', function () {
    $otherUser = User::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherUser->teams()->first()->id]);

    expect(fn () => Livewire::test(Show::class, ['server_uuid' => $otherServer->uuid]))
        ->toThrow(ModelNotFoundException::class);
});

it('moves traffic analytics configuration out of sentinel and onto analytics', function () {
    $analyticsView = file_get_contents(resource_path('views/livewire/server/traffic-analytics-settings.blade.php'));
    $sentinelView = file_get_contents(resource_path('views/livewire/server/sentinel.blade.php'));

    expect($analyticsView)
        ->toContain('id="server-traffic-analytics-settings-section"')
        ->toContain('title="Traffic analytics"')
        ->not->toContain('title="Traffic analytics settings"')
        ->toContain('id="trafficTopn"')
        ->toContain('id="trafficSampleThreshold"')
        ->toContain('id="trafficRetention1hDays"')
        ->toContain('id="trafficRetention1dDays"')
        ->toContain('id="isGeoipEnabled"')
        ->toContain('id="geoipRefreshDays"')
        ->toContain('id="geoipMaxmindLicenseKey"')
        ->and($sentinelView)
        ->not->toContain('id="server-sentinel-traffic-analytics-section"')
        ->not->toContain('id="trafficTopn"');
});

it('matches the server metrics empty state when traffic analytics is disabled', function () {
    $view = file_get_contents(resource_path('views/livewire/server/traffic-analytics-settings.blade.php'));
    $disabledState = str($view)
        ->after('@else')
        ->before('@endif')
        ->toString();

    expect($disabledState)
        ->toContain('title="Traffic analytics is disabled"')
        ->toContain('<x-slot:contents>')
        ->toContain('isHighlightedButton')
        ->toContain('buttonTitle="Enable traffic analytics"');
});

it('renders traffic analytics settings above the server analytics dashboard', function () {
    $view = file_get_contents(resource_path('views/livewire/server/analytics/show.blade.php'));

    expect(strpos($view, '<livewire:server.traffic-analytics-settings'))
        ->toBeLessThan(strpos($view, '<livewire:analytics'));
});

it('only lazy loads the scoped dashboard when traffic analytics is enabled', function () {
    $view = file_get_contents(resource_path('views/livewire/server/analytics/show.blade.php'));

    expect($view)
        ->toContain(':lazy="$server->isTrafficAnalyticsEnabled()"');
});

it('matches other server pages without a visible page title', function () {
    $view = file_get_contents(resource_path('views/livewire/server/analytics/show.blade.php'));

    expect($view)
        ->not->toContain('>Analytics</h1>')
        ->toContain('<livewire:server.traffic-analytics-settings');
});

it('scopes the server analytics page to its route server', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();
    $otherServer->settings->is_traffic_analytics_enabled = false;
    $otherServer->settings->save();

    Livewire::test(Analytics::class, ['scopedServerUuid' => $server->uuid])
        ->assertSet('scopedServerUuid', $server->uuid)
        ->assertDontSee($otherServer->name);
});

it('does not duplicate the disabled state on a scoped server analytics dashboard', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    Livewire::test(Analytics::class, ['scopedServerUuid' => $server->uuid])
        ->assertDontSee('Traffic analytics is not enabled');
});

it('removes stale analytics content when traffic analytics is disabled', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->save();

    $component = Livewire::test(Analytics::class, ['scopedServerUuid' => $server->uuid]);

    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    $component
        ->dispatch('trafficAnalyticsStateChanged')
        ->assertSet('servers', fn ($servers) => $servers->isEmpty())
        ->assertDontSee('No analytics data yet');
});

it('does not render a skeleton placeholder for a disabled scoped server', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    Livewire::test(Analytics::class, ['scopedServerUuid' => $server->uuid, 'lazy' => true])
        ->assertDontSee('analytics-overview-section')
        ->assertDontSee('analytics-requests-section');
});

it('saves traffic analytics settings from the server analytics page', function () {
    Queue::fake();

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    Livewire::test(TrafficAnalyticsSettings::class, ['server' => $server])
        ->set('trafficTopn', 100)
        ->set('trafficSampleThreshold', 500)
        ->set('trafficRetention1hDays', 14)
        ->set('trafficRetention1dDays', 180)
        ->set('isGeoipEnabled', false)
        ->set('geoipRefreshDays', 7)
        ->call('saveTrafficAnalyticsSettings')
        ->assertHasNoErrors();

    $settings = $server->settings->fresh();

    expect($settings->traffic_topn)->toBe(100)
        ->and($settings->traffic_sample_threshold)->toBe(500)
        ->and($settings->traffic_retention_1h_days)->toBe(14)
        ->and($settings->traffic_retention_1d_days)->toBe(180)
        ->and($settings->is_geoip_enabled)->toBeFalse()
        ->and($settings->geoip_refresh_days)->toBe(7);
});
