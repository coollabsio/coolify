<?php

use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('discovers managed container networks regardless of container status', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $commands = connectProxyToNetworks($server)->implode("\n");

    expect($commands)
        ->toContain('docker ps -a --filter label=coolify.managed=true')
        ->toContain('.NetworkSettings.Networks')
        ->toContain('docker network inspect "$network"')
        ->toContain('docker network connect "$network" coolify-proxy')
        ->not->toContain('docker network create');
});

it('skips Docker system networks during runtime reconciliation', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $commands = connectProxyToNetworks($server)->implode("\n");

    expect($commands)
        ->toContain('"$network" = "bridge"')
        ->toContain('"$network" = "host"')
        ->toContain('"$network" = "none"')
        ->toContain('"$network" = "default"');
});

it('preserves runtime reconciliation loops for non-root SSH users', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'user' => 'ubuntu',
    ]);

    $commands = collect(parseCommandsByLineForSudo(connectProxyToNetworks($server), $server))->implode("\n");

    expect($commands)
        ->toContain('$(sudo docker ps')
        ->toMatch('/sudo\s+docker inspect/')
        ->toMatch('/sudo\s+docker network inspect/')
        ->toMatch('/sudo\s+docker network connect/')
        ->not->toContain('sudo while')
        ->not->toContain('sudo done')
        ->not->toContain('sudo continue');
});
