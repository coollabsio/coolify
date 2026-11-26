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
 * Regression coverage for https://github.com/coollabsio/coolify/issues/6995:
 * each deployment parses the compose file again, and that parse must not reset the values
 * that the user saved, or write stale values into the compose file.
 */
function composeWithEditableVariables(string $hardcoded): string
{
    return <<<YAML
services:
  app:
    image: busybox:latest
    environment:
      - SIMPLE=\${SIMPLE}
      - WITH_DEFAULT=\${WITH_DEFAULT:-from-compose}
      - REQUIRED=\${REQUIRED:?}
      - HARDCODED={$hardcoded}
YAML;
}

/**
 * @return array{team: Team, server: Server, destination: StandaloneDocker, environment: Environment}
 */
function composeVariableEditsContext(): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    return [
        'team' => $team,
        'server' => $server,
        'destination' => StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail(),
        'environment' => Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]),
    ];
}

function saveComposeVariable(Application|Service $resource, string $key, string $value): void
{
    $resource->environment_variables()->where('key', $key)->firstOrFail()->update(['value' => $value]);
}

test('a service keeps saved variable values and uses the edited compose file when it is parsed again', function () {
    $context = composeVariableEditsContext();
    $service = Service::factory()->create([
        'server_id' => $context['server']->id,
        'environment_id' => $context['environment']->id,
        'destination_id' => $context['destination']->id,
        'destination_type' => $context['destination']->getMorphClass(),
        'docker_compose_raw' => composeWithEditableVariables('v1'),
    ]);

    $service->parse();
    saveComposeVariable($service, 'SIMPLE', 'user-simple');
    saveComposeVariable($service, 'WITH_DEFAULT', 'user-default');
    saveComposeVariable($service, 'REQUIRED', 'user-required');

    $service->refresh();
    $service->docker_compose_raw = composeWithEditableVariables('v2');
    $service->save();
    $service->parse();

    $environment = data_get(Yaml::parse($service->fresh()->docker_compose), 'services.app.environment');

    expect($service->environment_variables()->pluck('value', 'key')->only(['SIMPLE', 'WITH_DEFAULT', 'REQUIRED'])->all())
        ->toEqual(['SIMPLE' => 'user-simple', 'WITH_DEFAULT' => 'user-default', 'REQUIRED' => 'user-required'])
        // The compose file keeps the references, so Docker Compose reads the saved values from .env.
        ->and($environment)->toMatchArray([
            'SIMPLE' => '${SIMPLE}',
            'WITH_DEFAULT' => '${WITH_DEFAULT:-from-compose}',
            'REQUIRED' => '${REQUIRED:?}',
            'HARDCODED' => 'v2',
        ]);
});

test('a compose application keeps saved variable values and writes no stale values when it is parsed again', function () {
    $context = composeVariableEditsContext();
    $application = Application::factory()->create([
        'environment_id' => $context['environment']->id,
        'destination_id' => $context['destination']->id,
        'destination_type' => $context['destination']->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => composeWithEditableVariables('v1'),
    ]);

    applicationParser($application);
    saveComposeVariable($application, 'SIMPLE', 'first');
    saveComposeVariable($application, 'WITH_DEFAULT', 'first-default');
    saveComposeVariable($application, 'REQUIRED', 'first-required');
    applicationParser($application->fresh());

    saveComposeVariable($application, 'SIMPLE', 'second');
    saveComposeVariable($application, 'WITH_DEFAULT', 'second-default');
    saveComposeVariable($application, 'REQUIRED', 'second-required');
    $application->refresh();
    $application->docker_compose_raw = composeWithEditableVariables('v2');
    $application->save();
    $parsedCompose = applicationParser($application->fresh());

    expect($application->environment_variables()->where('is_preview', false)->pluck('value', 'key')->only(['SIMPLE', 'WITH_DEFAULT', 'REQUIRED'])->all())
        ->toEqual(['SIMPLE' => 'second', 'WITH_DEFAULT' => 'second-default', 'REQUIRED' => 'second-required'])
        ->and(data_get($parsedCompose, 'services.app.environment'))->toMatchArray([
            'SIMPLE' => '${SIMPLE}',
            'WITH_DEFAULT' => '${WITH_DEFAULT:-from-compose}',
            'REQUIRED' => '${REQUIRED:?}',
            'HARDCODED' => 'v2',
        ]);
});
