<?php

use App\Actions\Server\ConfigureTrafficAnalytics;
use App\Livewire\Analytics;
use App\Livewire\Server\TrafficAnalyticsSettings;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\User;
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

it('toggles traffic analytics via the sentinel settings component', function () {
    ConfigureTrafficAnalytics::partialMock()->shouldReceive('handle')->once()->andReturnUsing(function ($server, $enable) {
        $server->settings->is_traffic_analytics_enabled = $enable;
        $server->settings->save();
    });

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    // New servers default analytics on; start from the disabled state to exercise enabling.
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse();

    Livewire::test(TrafficAnalyticsSettings::class, ['server' => $server])
        ->call('toggleTrafficAnalytics')
        ->assertDispatchedTo(Analytics::class, 'trafficAnalyticsStateChanged')
        ->assertHasNoErrors();

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeTrue();
});

it('warns about the application interruption before enabling traffic analytics', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    Livewire::test(TrafficAnalyticsSettings::class, ['server' => $server])
        ->assertSee('Enable traffic analytics?')
        ->assertDontSeeHtml('wire:confirm')
        ->assertSeeHtml('wire:loading.flex')
        ->assertSeeHtml('wire:target="toggleTrafficAnalytics"')
        ->assertSee('Restarting Sentinel and proxy...')
        ->assertSee('Enabling traffic analytics will restart Sentinel and the proxy. Your applications will experience a brief interruption.');
});

it('allows the analytics toggle modal to update after the state changes', function () {
    $view = file_get_contents(resource_path('views/livewire/server/traffic-analytics-settings.blade.php'));

    expect($view)->toContain(':ignoreWire="false"');
});

it('does not enable traffic analytics on a swarm server', function () {
    ConfigureTrafficAnalytics::partialMock()->shouldReceive('handle')->never();

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_swarm_manager = true;
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse();

    Livewire::test(TrafficAnalyticsSettings::class, ['server' => $server])
        ->call('toggleTrafficAnalytics')
        ->assertHasNoErrors();

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse();
});

it('saves traffic analytics settings from the sentinel form', function () {
    Queue::fake();

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = true;
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

it('rejects a zero top-n cap', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(TrafficAnalyticsSettings::class, ['server' => $server])
        ->set('trafficTopn', 0)
        ->call('saveTrafficAnalyticsSettings')
        ->assertHasErrors(['trafficTopn']);
});

it('does not enable traffic analytics on a build server', function () {
    ConfigureTrafficAnalytics::partialMock()->shouldReceive('handle')->never();

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_build_server = true;
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse();

    Livewire::test(TrafficAnalyticsSettings::class, ['server' => $server])
        ->call('toggleTrafficAnalytics')
        ->assertHasNoErrors();

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse();
});
