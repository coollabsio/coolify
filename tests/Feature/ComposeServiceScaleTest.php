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

function scaleTestApplication(string $compose): Application
{
    return Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $compose,
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ])->fresh();
}

function scaleTestService(string $compose): Service
{
    return Service::factory()->create([
        'docker_compose_raw' => $compose,
        'environment_id' => test()->environment->id,
        'server_id' => test()->destination->server_id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
}

it('rejects a compose application service with more than one replica', function (string $compose, string $setting) {
    expect(fn () => applicationParser(scaleTestApplication($compose)))
        ->toThrow(Exception::class, "Service \"celery\" sets {$setting} to 2. Coolify sets a container_name for each service");
})->with([
    'scale' => ["services:\n  celery:\n    image: alpine\n    scale: 2\n", 'scale'],
    'deploy.replicas' => ["services:\n  celery:\n    image: alpine\n    deploy:\n      replicas: 2\n", 'deploy.replicas'],
]);

it('rejects a service stack service with more than one replica', function () {
    expect(fn () => serviceParser(scaleTestService("services:\n  celery:\n    image: alpine\n    scale: '2'\n")))
        ->toThrow(Exception::class, 'Service "celery" sets scale to 2.');
});

it('accepts one replica and a scale set from a variable', function () {
    $compose = applicationParser(scaleTestApplication(
        "services:\n  web:\n    image: alpine\n    scale: 1\n  worker:\n    image: alpine\n    deploy:\n      replicas: \${REPLICAS:-1}\n"
    ));

    expect(data_get($compose, 'services.web.scale'))->toBe(1)
        ->and(data_get($compose, 'services.worker.container_name'))->not->toBeEmpty();
});
