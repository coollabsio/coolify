<?php

use App\Actions\Server\ConfigureTrafficAnalytics;
use App\Livewire\Server\Sentinel;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
