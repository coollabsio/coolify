<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ServerFilesFromServerJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    config(['constants.ssh.mux_enabled' => false]);
    Bus::fake();
    Queue::fake();
    Process::fake(['*' => Process::result(output: '')]);

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
});

function composeParseDeploymentApplication(string $compose): Application
{
    return Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $compose,
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ])->fresh();
}

/**
 * Runs the Compose parse step of a deployment job.
 *
 * @return Collection<array-key, mixed>
 */
function runComposeParseDeploymentStep(Application $application): Collection
{
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('addLogEntry');

    foreach ([
        'application' => $application,
        'application_deployment_queue' => $queue,
        'pull_request_id' => 0,
        'preview' => null,
        'commit' => 'HEAD',
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    return (new ReflectionMethod(ApplicationDeploymentJob::class, 'parseComposeFileForDeployment'))->invoke($job);
}

it('stops the deployment when the Compose file gives no services, instead of writing an empty Compose file', function (string $compose) {
    $application = composeParseDeploymentApplication($compose);

    expect(fn () => runComposeParseDeploymentStep($application))
        ->toThrow(DeploymentException::class, 'The Docker Compose file has no services');

    Queue::assertNotPushed(ServerFilesFromServerJob::class);
})->with([
    'more collection aliases than the limit' => fn () => "x-defaults: &defaults\n  image: alpine:latest\nservices:\n"
        .collect(range(1, Application::MAX_DOCKER_COMPOSE_COLLECTION_ALIASES + 1))
            ->map(fn (int $index): string => "  service-{$index}:\n    <<: *defaults\n")
            ->implode(''),
    'invalid YAML' => "services:\n  web:\n    image: [nginx\n",
    'an empty services section' => "services: {}\n",
]);

it('deploys a Compose file that reuses YAML anchors more than 128 times', function () {
    $compose = "x-defaults: &defaults\n  image: alpine:latest\nservices:\n"
        .collect(range(1, 160))->map(fn (int $index): string => "  service-{$index}:\n    <<: *defaults\n")->implode('');
    $application = composeParseDeploymentApplication($compose);

    $composeFile = runComposeParseDeploymentStep($application);

    expect(data_get($composeFile, 'services'))->toHaveCount(160)
        ->and(data_get($composeFile, 'services.service-160.image'))->toBe('alpine:latest');
});
