<?php

use App\Actions\Server\ConfigureTrafficAnalytics;
use App\Livewire\Dashboard\TrafficAnalytics as DashboardTrafficAnalytics;
use App\Livewire\Server\Analytics;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

class NudgeEmptyTrafficClient extends SentinelTrafficClient
{
    protected function raw(string $url): string
    {
        return '[]';
    }
}

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

it('renders the server analytics nudge while traffic analytics is disabled', function () {
    $server = disabledServer();

    Livewire::test(Analytics::class, ['server_uuid' => $server->uuid])
        ->assertOk()
        ->assertSee('Turn on traffic analytics for this server')
        ->assertSee('restarts the proxy')
        ->assertSee('Enable traffic analytics');
});

it('enables traffic analytics from the server nudge and hides it', function () {
    ConfigureTrafficAnalytics::partialMock()->shouldReceive('handle')->once()->andReturnUsing(function ($server, $enable) {
        $server->settings->is_traffic_analytics_enabled = $enable;
        $server->settings->save();
    });

    $server = disabledServer();
    app()->bind(SentinelTrafficClient::class, fn () => new NudgeEmptyTrafficClient($server));

    Livewire::test(Analytics::class, ['server_uuid' => $server->uuid])
        ->assertSee('Turn on traffic analytics for this server')
        ->call('enableTrafficAnalytics')
        ->assertHasNoErrors()
        ->assertSet('enabled', true)
        ->assertDontSee('Turn on traffic analytics for this server');
});

it('shows the ineligible note instead of an enable button on a swarm server', function () {
    $server = disabledServer();
    $server->settings->is_swarm_manager = true;
    $server->settings->save();

    Livewire::test(Analytics::class, ['server_uuid' => $server->uuid])
        ->assertOk()
        ->assertSee('not available on Swarm or Build-pack servers')
        ->assertDontSee('Enable traffic analytics');
});

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
