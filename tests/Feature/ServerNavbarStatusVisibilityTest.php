<?php

use App\Enums\ProxyTypes;
use App\Livewire\Server\Navbar;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\ProxyDashboardCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
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

it('shows the first Sentinel report as pending instead of requiring attention', function () {
    [$server] = makeNavbarServer(isFunctional: true);
    $server->forceFill([
        'sentinel_updated_at' => now()->subDay(),
        'sentinel_waiting_since' => now(),
    ])->save();

    Livewire::test('server.navbar', ['server' => $server->fresh()])
        ->assertSee('Ready')
        ->assertSee('Waiting for first report')
        ->assertDontSee('Attention required')
        ->assertDontSee('Out of sync');
});

it('listens for sentinel status broadcasts', function () {
    [$server, , $team] = makeNavbarServer(isFunctional: true);

    Livewire::test('server.navbar', ['server' => $server])
        ->assertSet('server.uuid', $server->uuid);

    expect(app(Navbar::class)->getListeners())
        ->toHaveKey('sentinel-restart-requested', 'hideSentinelWarning')
        ->toHaveKey("echo-private:team.{$team->id},SentinelRestarted", 'refreshSentinelStatus')
        ->toHaveKey("echo-private:team.{$team->id},SentinelSynchronized", 'refreshSentinelStatus');
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
        ->assertDontSee('Out of sync')
        ->assertDispatched('sentinel-status-changed');
});

it('does not render the floating action panel when the server has no proxy actions', function () {
    [$server] = makeNavbarServer(isFunctional: false);

    Livewire::test('server.navbar', ['server' => $server])
        ->assertDontSeeHtml('x-teleport="#resource-action-hud-slot"');
});

it('renders the floating action panel with proxy actions for a functional server', function () {
    [$server] = makeNavbarServer(isFunctional: true);
    $server->forceFill(['proxy' => ['type' => ProxyTypes::TRAEFIK->value]])->save();
    $server = $server->fresh();

    expect($server->proxySet())->toBeTrue();

    Livewire::test('server.navbar', ['server' => $server])
        ->assertSeeHtml('x-teleport="#resource-action-hud-slot"')
        ->assertSee('Refresh Proxy Status');
});

it('shows the Traefik dashboard link without proxy actions to members who cannot manage the proxy', function () {
    [$server, $user, $team] = makeNavbarServer(isFunctional: true);
    $user->teams()->updateExistingPivot($team->id, ['role' => 'member']);
    $server->forceFill(['proxy' => ['type' => ProxyTypes::TRAEFIK->value, 'status' => 'running']])->save();
    $server = $server->fresh();
    Cache::put(ProxyDashboardCacheService::getCacheKey($server), true);

    Livewire::test('server.navbar', ['server' => $server])
        ->assertSeeHtml('x-teleport="#resource-action-hud-slot"')
        ->assertSee('Traefik Dashboard')
        ->assertDontSee('Refresh Proxy Status');
});

it('shows the current proxy status after a background status update', function () {
    [$server] = makeNavbarServer(isFunctional: true);
    $server->forceFill(['proxy' => ['type' => ProxyTypes::TRAEFIK->value, 'status' => 'starting']])->save();

    $component = Livewire::test('server.navbar', ['server' => $server->fresh()])
        ->assertSet('proxyStatus', 'starting');

    // Sentinel or the server check job marks the proxy as running without a UI broadcast.
    $server->proxy->set('status', 'running');
    $server->save();

    $component->call('refreshAgentStatus')
        ->assertSet('proxyStatus', 'running')
        ->assertDontSee('Starting');
});
