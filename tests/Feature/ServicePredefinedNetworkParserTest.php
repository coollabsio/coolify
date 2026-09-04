<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('adds the predefined Docker network when enabled for a service', function () {
    Bus::fake();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $destination->network = 'coolify-test';
    $destination->save();

    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'connect_to_docker_network' => true,
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:latest
YAML,
    ]);

    $parsedCompose = serviceParser($service);

    expect(data_get($parsedCompose, 'services.app.networks'))
        ->toHaveKey('coolify-test')
        ->and(data_get($parsedCompose, 'networks.coolify-test.name'))
        ->toBe('coolify-test')
        ->and(data_get($parsedCompose, 'networks.coolify-test.external'))
        ->toBeTrue();
});

it('does not add the predefined Docker network when disabled for a service', function () {
    Bus::fake();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $destination->network = 'coolify-test';
    $destination->save();

    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'connect_to_docker_network' => false,
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:latest
YAML,
    ]);

    $parsedCompose = serviceParser($service);

    expect(data_get($parsedCompose, 'services.app.networks'))
        ->not->toHaveKey('coolify-test')
        ->and(data_get($parsedCompose, 'networks.coolify-test'))
        ->toBeNull();
});
