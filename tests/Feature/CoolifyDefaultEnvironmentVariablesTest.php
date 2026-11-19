<?php

use App\Actions\Database\StartDragonfly;
use App\Actions\Database\StartPostgresql;
use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDragonfly;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::query()->firstOrCreate(['id' => 0]);
    Bus::fake();

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = $this->server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Shop $live']);
    $this->environment = Environment::factory()->create(['project_id' => $project->id, 'name' => 'staging']);
});

function coolifyDefaultsPostgres(array $attributes = []): StandalonePostgresql
{
    return StandalonePostgresql::forceCreate([
        'uuid' => 'coolify-defaults-db',
        'name' => 'Main database',
        'image' => 'postgres:16-alpine',
        'postgres_password' => 'password',
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        ...$attributes,
    ]);
}

/**
 * @return array<int, string>
 */
function coolifyDefaultsDatabaseEnvironment(object $action, $database): array
{
    $action->database = $database->fresh();

    return (new ReflectionMethod($action, 'generate_environment_variables'))->invoke($action);
}

/**
 * @return array<int, string>
 */
function coolifyDefaultsApplicationEnv(Application $application, bool $forBuildTime = false, ?Server $deploymentServer = null): array
{
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();
    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnNull();

    $properties = [
        'application' => $application->fresh(),
        'application_deployment_queue' => $queue,
        'build_pack' => 'nixpacks',
        'mainServer' => $deploymentServer ?? $application->destination->server,
        'pull_request_id' => 0,
        'commit' => 'HEAD',
        'branch' => 'main',
        'container_name' => 'coolify-defaults-app',
        'saved_outputs' => collect(),
    ];
    foreach ($properties as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    if ($forBuildTime) {
        return collect($reflection->getMethod('generate_coolify_env_variables')->invoke($job, true))
            ->map(fn ($value, $key) => "{$key}={$value}")->values()->all();
    }

    return collect($reflection->getMethod('generate_runtime_environment_variables')->invoke($job))->all();
}

function coolifyDefaultsApplication(): Application
{
    return Application::factory()->create([
        'name' => 'My web app',
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
}

function coolifyDefaultsService(string $environmentYaml = ''): Service
{
    $service = Service::factory()->create([
        'name' => 'blog',
        'environment_id' => test()->environment->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:latest\n{$environmentYaml}",
    ]);
    ServiceApplication::create(['name' => 'app', 'service_id' => $service->id]);

    return $service;
}

test('a database gets the same Coolify variables as other resources, with compose-safe values', function () {
    $environment = coolifyDefaultsDatabaseEnvironment(new StartPostgresql, coolifyDefaultsPostgres());

    expect($environment)
        ->toContain('COOLIFY_RESOURCE_UUID=coolify-defaults-db')
        ->toContain('COOLIFY_CONTAINER_NAME=coolify-defaults-db')
        ->toContain('COOLIFY_APP_NAME=Main database')
        ->toContain('COOLIFY_PROJECT_NAME=Shop $$live')
        ->toContain('COOLIFY_ENVIRONMENT_NAME=staging')
        ->toContain("COOLIFY_SERVER_IP={$this->server->ip}");
});

test('a Dragonfly database also gets the Coolify variables', function () {
    $database = StandaloneDragonfly::forceCreate([
        'uuid' => 'coolify-defaults-dragonfly',
        'name' => 'Cache',
        'image' => 'docker.dragonflydb.io/dragonflydb/dragonfly',
        'dragonfly_password' => 'password',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    expect(coolifyDefaultsDatabaseEnvironment(new StartDragonfly, $database))
        ->toContain('COOLIFY_RESOURCE_UUID=coolify-defaults-dragonfly')
        ->toContain('COOLIFY_APP_NAME=Cache');
});

test('a database variable that the user defines wins over the Coolify variable', function () {
    $database = coolifyDefaultsPostgres();
    $database->runtime_environment_variables()->create(['key' => 'COOLIFY_APP_NAME', 'value' => 'custom']);

    $appNames = collect(coolifyDefaultsDatabaseEnvironment(new StartPostgresql, $database))
        ->filter(fn ($variable) => str_starts_with($variable, 'COOLIFY_APP_NAME='));

    expect($appNames->values()->all())->toBe(['COOLIFY_APP_NAME=custom']);
});

test('a database without the option gets no default variables but keeps its identity variables', function () {
    $environment = coolifyDefaultsDatabaseEnvironment(new StartPostgresql, coolifyDefaultsPostgres(['include_coolify_default_variables' => false]));

    expect(collect($environment)->first(fn ($variable) => str_starts_with($variable, 'COOLIFY_APP_NAME=')))->toBeNull()
        ->and(collect($environment)->first(fn ($variable) => str_starts_with($variable, 'COOLIFY_SERVER_IP=')))->toBeNull()
        ->and($environment)->toContain('COOLIFY_RESOURCE_UUID=coolify-defaults-db');
});

test('an application gets the default variables as quoted lines in its runtime .env file', function () {
    $environment = coolifyDefaultsApplicationEnv(coolifyDefaultsApplication());

    expect($environment)
        ->toContain('COOLIFY_APP_NAME="My web app"')
        ->toContain('COOLIFY_PROJECT_NAME="Shop \\$live"')
        ->toContain('COOLIFY_ENVIRONMENT_NAME="staging"')
        ->toContain("COOLIFY_SERVER_IP=\"{$this->server->ip}\"");
});

test('an application on an additional server gets the IP of the server it runs on', function () {
    $additionalServer = Server::factory()->create(['team_id' => $this->team->id, 'ip' => '203.0.113.7']);

    expect(coolifyDefaultsApplicationEnv(coolifyDefaultsApplication(), deploymentServer: $additionalServer))
        ->toContain('COOLIFY_SERVER_IP="203.0.113.7"');
});

test('an application build does not get the default variables', function () {
    $environment = coolifyDefaultsApplicationEnv(coolifyDefaultsApplication(), forBuildTime: true);

    expect(collect($environment)->first(fn ($variable) => str_starts_with($variable, 'COOLIFY_APP_NAME=')))->toBeNull()
        ->and(collect($environment)->first(fn ($variable) => str_starts_with($variable, 'COOLIFY_RESOURCE_UUID=')))->not->toBeNull();
});

test('an application without the option gets no default variables', function () {
    $application = coolifyDefaultsApplication();
    $application->settings->update(['include_coolify_default_variables' => false]);

    $environment = coolifyDefaultsApplicationEnv($application);

    expect(collect($environment)->first(fn ($variable) => str_starts_with($variable, 'COOLIFY_APP_NAME=')))->toBeNull();
});

test('a service variable defined in the compose file wins over the Coolify variable', function () {
    $service = coolifyDefaultsService("    environment:\n      COOLIFY_APP_NAME: custom\n");

    $environment = data_get(serviceParser($service), 'services.app.environment');

    expect($environment['COOLIFY_APP_NAME'])->toBe('custom')
        ->and($environment['COOLIFY_PROJECT_NAME'])->toBe('Shop $$live');
});

test('a service without the option gets no default variables', function () {
    $service = coolifyDefaultsService();
    $service->forceFill(['include_coolify_default_variables' => false])->save();

    $environment = collect(data_get(serviceParser($service->fresh()), 'services.app.environment'));

    expect($environment->has('COOLIFY_APP_NAME'))->toBeFalse()
        ->and($environment->has('COOLIFY_RESOURCE_UUID'))->toBeTrue();
});

test('a team admin turns the option off from the environment variables page', function (string $resourceType) {
    $user = User::factory()->create();
    $this->team->members()->attach($user, ['role' => 'admin']);
    $this->actingAs($user);
    $resource = match ($resourceType) {
        'application' => coolifyDefaultsApplication(),
        'service' => coolifyDefaultsService(),
        'database' => coolifyDefaultsPostgres(),
    };

    Livewire::test(All::class, ['resource' => $resource])
        ->assertSet('include_coolify_default_variables', true)
        ->set('include_coolify_default_variables', false)
        ->call('saveCoolifyDefaultVariables');

    $owner = $resource instanceof Application ? $resource->settings->fresh() : $resource->fresh();
    expect($owner->include_coolify_default_variables)->toBeFalse();
})->with(['application', 'service', 'database']);

test('a team member cannot change the option', function () {
    $user = User::factory()->create();
    $this->team->members()->attach($user, ['role' => 'member']);
    $this->actingAs($user);
    $database = coolifyDefaultsPostgres();

    Livewire::test(All::class, ['resource' => $database])
        ->set('include_coolify_default_variables', false)
        ->call('saveCoolifyDefaultVariables');

    expect($database->fresh()->include_coolify_default_variables)->toBeTrue();
});
