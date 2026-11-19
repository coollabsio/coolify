<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

/**
 * Regression coverage for https://github.com/coollabsio/coolify/issues/6401: a reference to
 * COOLIFY_RESOURCE_UUID in the compose file created a resource variable (with the network name
 * as its value), and that variable replaced the resource UUID in every container.
 */
function composeThatReferencesResourceUuid(): string
{
    return <<<'YAML'
services:
  app:
    image: nginx:alpine
    environment:
      - MY_UUID=${COOLIFY_RESOURCE_UUID}
      - MY_URL=${COOLIFY_URL:-http://localhost}
      - COOLIFY_CONTAINER_NAME
    networks:
      - mynet
networks:
  mynet:
    name: ${COOLIFY_RESOURCE_UUID:-mynet}
YAML;
}

function composeResourceUuidDestination(): StandaloneDocker
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    return StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
}

function composeResourceUuidEnvironment(): Environment
{
    return Environment::factory()->create(['project_id' => Project::factory()->create()->id]);
}

/**
 * @param  array<string, mixed>  $compose
 */
function expectComposeKeepsCoolifyResourceUuid(Application|Service $resource, array $compose): void
{
    expect($resource->environment_variables()->pluck('key')->all())->toBe([])
        ->and(data_get($compose, 'services.app.environment.COOLIFY_RESOURCE_UUID'))->toBe($resource->uuid)
        ->and(data_get($compose, 'services.app.environment.COOLIFY_CONTAINER_NAME'))->not->toBeEmpty()
        ->and(data_get($compose, 'services.app.environment.MY_UUID'))->toBe('${COOLIFY_RESOURCE_UUID}')
        ->and(data_get($compose, 'networks.mynet.name'))->toBe('${COOLIFY_RESOURCE_UUID:-mynet}');
}

test('a compose application keeps its resource UUID when the compose file references Coolify variables', function () {
    $destination = composeResourceUuidDestination();
    $application = Application::factory()->create([
        'environment_id' => composeResourceUuidEnvironment()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => composeThatReferencesResourceUuid(),
    ]);

    applicationParser($application);
    $compose = applicationParser($application->fresh());

    expectComposeKeepsCoolifyResourceUuid($application, $compose->toArray());
});

test('a service keeps its resource UUID when the compose file references Coolify variables', function () {
    $destination = composeResourceUuidDestination();
    $service = Service::factory()->create([
        'server_id' => $destination->server_id,
        'environment_id' => composeResourceUuidEnvironment()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => composeThatReferencesResourceUuid(),
    ]);

    $service->parse();
    $service->refresh()->parse();

    expectComposeKeepsCoolifyResourceUuid($service, Yaml::parse($service->fresh()->docker_compose));
});

test('a COOLIFY_RESOURCE_UUID variable that the user saves still replaces the resource UUID', function () {
    $destination = composeResourceUuidDestination();
    $application = Application::factory()->create([
        'environment_id' => composeResourceUuidEnvironment()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => composeThatReferencesResourceUuid(),
    ]);
    $application->environment_variables()->create(['key' => 'COOLIFY_RESOURCE_UUID', 'value' => 'custom', 'is_preview' => false]);

    $compose = applicationParser($application->fresh());

    expect(data_get($compose, 'services.app.environment'))->not->toHaveKey('COOLIFY_RESOURCE_UUID')
        ->and($application->environment_variables()->where('key', 'COOLIFY_RESOURCE_UUID')->value('value'))->toBe('custom');
});
