<?php

use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

test('an empty service compose contributes no networks and does not block proxy network setup', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);

    $service = Service::factory()->create([
        'docker_compose_raw' => '',
        'docker_compose' => "services:\n  app:\n    image: nginx:alpine\n",
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $project->environments()->firstOrFail()->id,
    ]);

    expect($service->networks())->toBeInstanceOf(Collection::class)->toBeEmpty();

    $commands = ensureProxyNetworksExist($server)->implode("\n");

    expect($commands)
        ->toContain("docker network inspect 'coolify'")
        ->not->toContain("docker network inspect ''");
});
