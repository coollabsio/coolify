<?php

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
