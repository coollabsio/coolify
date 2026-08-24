<?php

use App\Actions\Server\ConfigureTrafficAnalytics;
use App\Livewire\Server\Sentinel;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
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

    Livewire::test(Sentinel::class, ['server' => $server])
        ->call('toggleTrafficAnalytics')
        ->assertHasNoErrors();

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeTrue();
});

it('does not enable traffic analytics on a swarm server', function () {
    ConfigureTrafficAnalytics::partialMock()->shouldReceive('handle')->never();

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_swarm_manager = true;
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse();

    Livewire::test(Sentinel::class, ['server' => $server])
        ->call('toggleTrafficAnalytics')
        ->assertHasNoErrors();

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse();
});

it('saves traffic analytics settings from the sentinel form', function () {
    Queue::fake();

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->save();

    Livewire::test(Sentinel::class, ['server' => $server])
        ->set('trafficTopn', 100)
        ->set('trafficSampleThreshold', 500)
        ->set('trafficRetention1hDays', 14)
        ->set('trafficRetention1dDays', 180)
        ->set('isGeoipEnabled', false)
        ->set('geoipRefreshDays', 7)
        ->call('submit')
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

    Livewire::test(Sentinel::class, ['server' => $server])
        ->set('trafficTopn', 0)
        ->call('submit')
        ->assertHasErrors(['trafficTopn']);
});

it('does not enable traffic analytics on a build server', function () {
    ConfigureTrafficAnalytics::partialMock()->shouldReceive('handle')->never();

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_build_server = true;
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse();

    Livewire::test(Sentinel::class, ['server' => $server])
        ->call('toggleTrafficAnalytics')
        ->assertHasNoErrors();

    expect($server->fresh()->isTrafficAnalyticsEnabled())->toBeFalse();
});
