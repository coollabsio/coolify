<?php

use App\Livewire\Server\Navbar;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);
});

function makeNavbarServer(bool $isFunctional): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => 'admin']);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'sentinel_updated_at' => now(),
    ]);

    $server->settings()->update([
        'is_reachable' => $isFunctional,
        'is_usable' => $isFunctional,
        'is_sentinel_enabled' => true,
    ]);

    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return [$server->fresh(), $user, $team];
}

it('does not show sentinel sync status before the server is validated', function () {
    [$server] = makeNavbarServer(isFunctional: false);

    Livewire::test('server.navbar', ['server' => $server])
        ->assertDontSee('Sentinel')
        ->assertDontSee('In sync')
        ->assertDontSee('Out of sync');
});

it('shows sentinel sync status after the server is validated', function () {
    [$server] = makeNavbarServer(isFunctional: true);

    Livewire::test('server.navbar', ['server' => $server])
        ->assertSee('Sentinel')
        ->assertSee('In sync');
});

it('listens for sentinel restarted broadcasts', function () {
    [$server, , $team] = makeNavbarServer(isFunctional: true);

    Livewire::test('server.navbar', ['server' => $server])
        ->assertSet('server.uuid', $server->uuid);

    expect(app(Navbar::class)->getListeners())
        ->toHaveKey("echo-private:team.{$team->id},SentinelRestarted", 'refreshSentinelStatus');
});

it('refreshes sentinel status when sentinel restarts for the server', function () {
    [$server] = makeNavbarServer(isFunctional: true);
    $server->forceFill(['sentinel_updated_at' => now()->subDay()])->save();

    expect($server->fresh()->isSentinelLive())->toBeFalse();

    $component = Livewire::test('server.navbar', ['server' => $server->fresh()])
        ->assertSee('Sentinel')
        ->assertSee('Out of sync');

    $server->sentinelHeartbeat();

    $component
        ->call('refreshSentinelStatus', ['serverUuid' => $server->uuid])
        ->assertSee('In sync')
        ->assertDontSee('Out of sync');
});
