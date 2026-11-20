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

it('lets Docker Compose name the containers of a compose application service with more than one replica', function (string $compose) {
    expect(data_get(applicationParser(scaleTestApplication($compose)), 'services.celery'))->not->toHaveKey('container_name');
})->with([
    'scale' => ["services:\n  celery:\n    image: alpine\n    scale: 2\n"],
    'deploy.replicas' => ["services:\n  celery:\n    image: alpine\n    deploy:\n      replicas: 2\n"],
]);

it('runs one container of a compose application service with more than one replica in a preview', function () {
    $compose = "services:\n  celery:\n    image: alpine\n    scale: 2\n    deploy:\n      labels:\n        keep: me\n";
    $service = data_get(applicationParser(scaleTestApplication($compose), pull_request_id: 7), 'services.celery-pr-7');

    expect($service)->toHaveKey('container_name')
        ->not->toHaveKey('scale')
        ->and(data_get($service, 'deploy'))->toBe(['labels' => ['keep' => 'me'], 'replicas' => 1]);
});

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
