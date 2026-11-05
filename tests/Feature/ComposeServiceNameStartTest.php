<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

function serviceNameStartCompose(string $serviceName): string
{
    return "services:\n  '{$serviceName}':\n    image: alpine\n";
}

function serviceNameStartApplication(string $compose): Application
{
    return Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $compose,
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ])->fresh();
}

function serviceNameStartService(string $compose): Service
{
    return Service::factory()->create([
        'docker_compose_raw' => $compose,
        'environment_id' => test()->environment->id,
        'server_id' => test()->destination->server_id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
}

it('rejects a service name that does not follow the Docker container name rule', function (string $serviceName) {
    $compose = serviceNameStartCompose($serviceName);
    $message = "Service name \"{$serviceName}\" must start with a letter or a digit and contain only letters, digits,";

    expect(fn () => validateDockerComposeForInjection($compose))->toThrow(Exception::class, $message)
        ->and(fn () => applicationParser(serviceNameStartApplication($compose)))->toThrow(Exception::class, $message)
        ->and(fn () => serviceParser(serviceNameStartService($compose)))->toThrow(Exception::class, $message);
})->with(['.api', '-api', '_api', 'api@x', 'api:x', 'api+x', 'äpi', 'api x', 'api/x']);

it('accepts service names that follow the Docker container name rule', function (string $serviceName) {
    $compose = serviceNameStartCompose($serviceName);
    validateDockerComposeForInjection($compose);
    $service = serviceNameStartService($compose);

    $parsed = serviceParser($service);

    expect(data_get($parsed, ['services', $serviceName, 'container_name']))->toBe("{$serviceName}-{$service->uuid}")
        ->and(data_get(applicationParser(serviceNameStartApplication($compose)), ['services', $serviceName, 'image']))->toBe('alpine');
})->with(['api.test', 'API', '9api', '1', 'a..b', 'api_', 'api-']);
