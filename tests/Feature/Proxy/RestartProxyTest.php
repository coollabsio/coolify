<?php

use App\Enums\ProxyTypes;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function setupProxyUser(string $role): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role]);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'name' => 'Test Server',
        'ip' => '192.168.1.100',
    ]);

    return [$user, $team, $server];
}

function makeServerProxyRunning(Server $server): void
{
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $server->proxy->status = 'running';
    $server->proxy->type = ProxyTypes::TRAEFIK->value;
    $server->save();
    $server->refresh();
}

test('member cannot see proxy restart and stop buttons', function () {
    [$user, $team, $server] = setupProxyUser('member');
    makeServerProxyRunning($server);

    // Mock proxySet to bypass SQLite boolean casting issue with force_disabled
    $mock = Mockery::mock($server)->makePartial();
    $mock->shouldReceive('proxySet')->andReturn(true);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test('server.navbar', ['server' => $mock])
        ->assertDontSee('Restart Proxy')
        ->assertDontSee('Stop Proxy');
});

test('admin can see proxy restart and stop buttons', function () {
    [$user, $team, $server] = setupProxyUser('admin');
    makeServerProxyRunning($server);

    $mock = Mockery::mock($server)->makePartial();
    $mock->shouldReceive('proxySet')->andReturn(true);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test('server.navbar', ['server' => $mock])
        ->assertSee('Restart Proxy')
        ->assertSee('Stop Proxy');
});

test('running proxy shows pending configuration warning when saved settings differ from applied settings', function () {
    [$user, $team, $server] = setupProxyUser('admin');
    makeServerProxyRunning($server);
    $server->proxy->last_saved_settings = 'saved-hash';
    $server->proxy->last_applied_settings = 'applied-hash';
    $server->detected_traefik_version = '3.6.23';
    $server->traefik_outdated_info = [
        'current' => '3.6.23',
        'latest' => '3.7.8',
        'type' => 'minor_upgrade',
        'upgrade_target' => 'v3.7',
    ];
    $server->save();

    expect($server->fresh()->hasPendingProxyConfiguration())->toBeTrue();

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $component = Livewire::test('server.navbar', ['server' => $server->fresh()])
        ->assertSee('Changes pending')
        ->assertSee('The saved proxy configuration has not been applied')
        ->assertSee('Restart proxy');

    $server->refresh();
    $server->proxy->last_applied_settings = 'saved-hash';
    $server->traefik_outdated_info = null;
    $server->save();

    $component->call('showNotification')
        ->assertDispatched('proxy-configuration-state-changed', pending: false, traefikOutdated: false)
        ->assertDontSee('The saved proxy configuration has not been applied');
});

test('running proxy hides pending configuration warning when saved settings match applied settings', function () {
    [$user, $team, $server] = setupProxyUser('admin');
    makeServerProxyRunning($server);
    $server->proxy->last_saved_settings = 'matching-hash';
    $server->proxy->last_applied_settings = 'matching-hash';
    $server->save();

    expect($server->fresh()->hasPendingProxyConfiguration())->toBeFalse();

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $component = Livewire::test('server.navbar', ['server' => $server->fresh()])
        ->assertDontSee('The saved proxy configuration has not been applied');

    $server->refresh();
    $server->proxy->last_saved_settings = 'new-saved-hash';
    $server->save();

    $component->dispatch('refreshServerShow')
        ->assertDispatched('proxy-configuration-state-changed', pending: true, traefikOutdated: false)
        ->assertSee('Changes pending')
        ->assertSee('The saved proxy configuration has not been applied');
});

test('admin can stop a proxy while it is starting', function () {
    [$user, $team, $server] = setupProxyUser('admin');

    $server->proxy->status = 'starting';
    $server->proxy->type = ProxyTypes::TRAEFIK->value;
    $server->save();
    $server->refresh();

    $mock = Mockery::mock($server)->makePartial();
    $mock->shouldReceive('proxySet')->andReturn(true);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test('server.navbar', ['server' => $mock])
        ->assertSee('Stop Proxy')
        ->assertDontSee('Start Proxy');
});

test('member cannot see start proxy button', function () {
    [$user, $team, $server] = setupProxyUser('member');

    $server->proxy->status = 'exited';
    $server->proxy->type = ProxyTypes::TRAEFIK->value;
    $server->save();
    $server->refresh();

    $mock = Mockery::mock($server)->makePartial();
    $mock->shouldReceive('proxySet')->andReturn(true);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test('server.navbar', ['server' => $mock])
        ->assertDontSee('Start Proxy');
});

test('start proxy button shows a loading state while proxy startup actions run', function () {
    [$user, $team, $server] = setupProxyUser('admin');

    $server->proxy->status = 'exited';
    $server->proxy->type = ProxyTypes::TRAEFIK->value;
    $server->save();
    $server->refresh();

    $mock = Mockery::mock($server)->makePartial();
    $mock->shouldReceive('proxySet')->andReturn(true);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test('server.navbar', ['server' => $mock])
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->assertSeeHtml('wire:loading.class="is-loading"')
        ->assertSeeHtml('wire:target="checkProxy,startProxy"');
});
