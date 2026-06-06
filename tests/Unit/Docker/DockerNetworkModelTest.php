<?php

use App\Models\DockerNetwork;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function createDockerNetwork(array $attributes = []): DockerNetwork
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    return DockerNetwork::create(array_merge([
        'server_id' => $server->id,
        'display_name' => 'Backend Private Network',
        'docker_network_name' => 'coolify-net-test',
    ], $attributes));
}

it('allows display name changes but keeps docker network name immutable', function () {
    $dockerNetwork = createDockerNetwork();

    $dockerNetwork->update([
        'display_name' => 'Renamed Network',
        'docker_network_name' => 'coolify-net-renamed',
    ]);

    $dockerNetwork->refresh();

    expect($dockerNetwork->display_name)->toBe('Renamed Network')
        ->and($dockerNetwork->docker_network_name)->toBe('coolify-net-test');
});

it('keeps server assignment immutable after creation', function () {
    $dockerNetwork = createDockerNetwork();
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);

    $dockerNetwork->update(['server_id' => $otherServer->id]);

    $dockerNetwork->refresh();

    expect($dockerNetwork->server_id)->not->toBe($otherServer->id);
});

it('validates docker network names', function () {
    createDockerNetwork(['docker_network_name' => 'valid_network-1.2']);

    expect(fn () => createDockerNetwork(['docker_network_name' => 'invalid network;rm']))
        ->toThrow(InvalidArgumentException::class);
});
