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
    InstanceSettings::create(['id' => 0]);
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
