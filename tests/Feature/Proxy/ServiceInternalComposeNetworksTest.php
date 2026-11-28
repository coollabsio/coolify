<?php

use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createServiceWithRawCompose(string $compose): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);

    $service = Service::factory()->create([
        'docker_compose_raw' => $compose,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $project->environments()->firstOrFail()->id,
    ]);

    return [$server, $service, $destination];
}

test('proxy network setup does not create the internal compose networks of a service', function () {
    [$server, $service, $destination] = createServiceWithRawCompose(<<<'YAML'
        services:
          app:
            image: nginx:alpine
            networks: [backend, shared]
          db:
            image: postgres:16-alpine
            networks: [backend, named]
        networks:
          backend: {}
          named:
            name: explicit-internal
          shared:
            name: my-shared-network
            external: true
        YAML);

    expect($service->proxyNetworks()->all())->toBe([$service->uuid, 'my-shared-network']);

    $commands = ensureProxyNetworksExist($server)->implode("\n");

    expect($commands)
        ->toContain(dockerProxyNetworkEnsureCommand($destination->network))
        ->toContain(dockerProxyNetworkEnsureCommand($service->uuid))
        ->toContain(dockerProxyNetworkEnsureCommand('my-shared-network'))
        ->not->toContain(dockerProxyNetworkEnsureCommand('backend'))
        ->not->toContain(dockerProxyNetworkEnsureCommand('named'))
        ->not->toContain(dockerProxyNetworkEnsureCommand('explicit-internal'))
        ->not->toContain(dockerProxyNetworkEnsureCommand('shared'));
});

test('an invalid service compose still ensures the stack network of the service', function () {
    [$server, $service] = createServiceWithRawCompose("services: [\n");

    expect($service->proxyNetworks()->all())->toBe([$service->uuid]);
    expect(ensureProxyNetworksExist($server)->implode("\n"))->toContain(dockerProxyNetworkEnsureCommand($service->uuid));
});
