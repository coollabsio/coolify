<?php

use App\Actions\Service\StartService;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ServerFilesFromServerJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

const SYNC_TRIGGERS_COMPOSE = <<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - app-data:/data
      - ./data:/app/data
      - type: bind
        source: ./config/app.conf
        target: /etc/app.conf
        content: 'listen 8080;'
volumes:
  app-data:
YAML;

beforeEach(function () {
    Bus::fake();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => SYNC_TRIGGERS_COMPOSE,
    ]);
});

it('does not queue a server file sync when it parses a compose application', function () {
    $this->application->parse();
    $this->application->parse(pull_request_id: 7);

    Bus::assertNotDispatched(ServerFilesFromServerJob::class);
});

it('does not queue a server file sync when it parses a service', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->destination->server_id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => SYNC_TRIGGERS_COMPOSE,
    ]);
    ServiceApplication::create(['name' => 'app', 'service_id' => $service->id]);

    $service->parse();

    Bus::assertNotDispatched(ServerFilesFromServerJob::class);
});

it('queues one server file sync for a compose deployment of the application only', function (int $pullRequestId, int $expectedSyncs) {
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnNull();
    foreach ([
        'application' => $this->application,
        'application_deployment_queue' => $queue,
        'pull_request_id' => $pullRequestId,
        'commit' => 'HEAD',
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    (new ReflectionMethod(ApplicationDeploymentJob::class, 'parseComposeFileForDeployment'))->invoke($job);

    Bus::assertDispatchedTimes(ServerFilesFromServerJob::class, $expectedSyncs);
})->with([
    'application' => [0, 1],
    'pull request preview' => [7, 0],
]);

it('reads the compose service names without a parse', function () {
    $this->application->update(['docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  db:\n    image: postgres:16\n"]);

    expect(array_keys($this->application->composeServicesWithoutParse()))->toBe(['web', 'db'])
        ->and($this->application->fileStorages()->count())->toBe(0)
        ->and($this->application->persistentStorages()->count())->toBe(0);

    $this->application->update(['docker_compose_raw' => "services: [\n"]);
    expect($this->application->composeServicesWithoutParse())->toBe([]);
});

it('queues one server file sync for each service resource with file storages when the service starts', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->destination->server_id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n    volumes:\n      - ./data:/app/data\n  cache:\n    image: redis:7\n",
    ]);
    $app = ServiceApplication::create(['name' => 'app', 'service_id' => $service->id]);
    ServiceApplication::create(['name' => 'cache', 'service_id' => $service->id]);
    $service->parse();

    StartService::queueFileStorageSync($service);

    Bus::assertDispatchedTimes(ServerFilesFromServerJob::class, 1);
    Bus::assertDispatched(ServerFilesFromServerJob::class, fn (ServerFilesFromServerJob $job) => $job->resource->is($app));
});
