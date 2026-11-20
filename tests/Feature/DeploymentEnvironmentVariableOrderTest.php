<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/**
 * A Compose application where the URI variable is created before the variable it references,
 * and then moved below it in the Developer view (the `order` column).
 */
function makeApplicationWithReorderedVariables(): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n",
        'docker_compose' => "services:\n  app:\n    image: nginx:alpine\n",
    ]);

    foreach ([['ZZ_URI', 'mongodb://${AA_USER}@db', 2], ['AA_USER', 'admin', 1]] as [$key, $value, $order]) {
        EnvironmentVariable::create([
            'key' => $key,
            'value' => $value,
            'order' => $order,
            'is_runtime' => true,
            'is_buildtime' => true,
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
        ]);
    }

    return $application;
}

/** @return Collection<int, string> the keys in the order the job writes them to the .env file */
function deploymentEnvKeys(Application $application, string $method): Collection
{
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnNull();

    foreach ([
        'application' => $application->fresh(),
        'application_deployment_queue' => $queue,
        'build_pack' => 'dockercompose',
        'mainServer' => $application->destination->server,
        'pull_request_id' => 0,
        'commit' => 'HEAD',
        'container_name' => 'order-app',
        'saved_outputs' => collect(),
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    return collect($reflection->getMethod($method)->invoke($job))
        ->map(fn (string $line): string => str($line)->before('=')->value())
        ->filter(fn (string $key): bool => in_array($key, ['AA_USER', 'ZZ_URI'], true))
        ->values();
}

test('the deployment .env files list variables in the order of the Environment Variables page', function (string $method) {
    $application = makeApplicationWithReorderedVariables();

    expect(deploymentEnvKeys($application, $method)->all())->toBe(['AA_USER', 'ZZ_URI']);
})->with(['generate_runtime_environment_variables', 'generate_buildtime_environment_variables']);

test('alphabetical order still sorts the deployment .env files by key', function (string $method) {
    $application = makeApplicationWithReorderedVariables();
    EnvironmentVariable::query()->where('key', 'AA_USER')->update(['order' => 3]);
    $application->settings->update(['is_env_sorting_enabled' => true]);

    expect(deploymentEnvKeys($application, $method)->all())->toBe(['AA_USER', 'ZZ_URI']);
})->with(['generate_runtime_environment_variables', 'generate_buildtime_environment_variables']);
