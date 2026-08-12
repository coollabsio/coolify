<?php

use App\Livewire\Dashboard\TrafficAnalytics as DashboardTrafficAnalytics;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

function disabledServer(): Server
{
    $server = Server::factory()->create([
        'team_id' => test()->team->id,
        'private_key_id' => test()->privateKey->id,
    ]);
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    return $server;
}

it('shows the dashboard nudge when an eligible server has analytics disabled', function () {
    disabledServer();

    Livewire::test(DashboardTrafficAnalytics::class)
        ->assertOk()
        ->assertSee('can start collecting traffic analytics');
});

it('does not count swarm or build servers in the dashboard nudge', function () {
    $swarm = disabledServer();
    $swarm->settings->is_swarm_manager = true;
    $swarm->settings->save();

    $build = disabledServer();
    $build->settings->is_build_server = true;
    $build->settings->save();

    Livewire::test(DashboardTrafficAnalytics::class)
        ->assertOk()
        ->assertDontSee('can start collecting traffic analytics');
});
