<?php

use App\Actions\Infisical\ResolveInheritedSecrets;
use App\Actions\Infisical\SyncBindingSafely;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InfisicalBinding;
use App\Models\InfisicalConnection;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('resource level variables take precedence over inherited ones', function () {
    $binding = InfisicalBinding::factory()->create();
    SharedEnvironmentVariable::create([
        'key' => 'DB_PASSWORD',
        'value' => 'from-infisical',
        'type' => 'environment',
        'team_id' => $binding->connection->team_id,
        'environment_id' => $binding->environment_id,
        'infisical_binding_id' => $binding->id,
    ]);

    $application = Application::factory()->create(['environment_id' => $binding->environment_id]);
    $application->environment_variables()->create([
        'key' => 'DB_PASSWORD',
        'value' => 'from-resource',
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    $inherited = ResolveInheritedSecrets::run($application);
    $merged = $inherited->merge(
        $application->runtime_environment_variables->mapWithKeys(
            fn ($variable) => [$variable->key => $variable->value]
        )
    );

    expect($merged['DB_PASSWORD'])->toBe('from-resource');
});

test('inherited secrets with no resource override survive the merge', function () {
    $binding = InfisicalBinding::factory()->create();
    SharedEnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'from-infisical',
        'type' => 'environment',
        'team_id' => $binding->connection->team_id,
        'environment_id' => $binding->environment_id,
        'infisical_binding_id' => $binding->id,
    ]);

    $application = Application::factory()->create(['environment_id' => $binding->environment_id]);

    expect(ResolveInheritedSecrets::run($application)['API_KEY'])->toBe('from-infisical');
});

test('a failing infisical sync at deploy time does not throw', function () {
    Http::fake(['*' => Http::response([], 500)]);
    Log::spy();

    $binding = InfisicalBinding::factory()->create();

    expect(fn () => SyncBindingSafely::run($binding))->not->toThrow(Exception::class);

    Log::shouldHaveReceived('warning')->once();
});

/**
 * Minimal harness that drives the real private generators on ApplicationDeploymentJob.
 *
 * The job is never constructed normally here: properties are injected by reflection,
 * exactly like tests/Feature/ApplicationDeploymentControlVarFilteringTest.php does.
 */
class InfisicalInjectionDeploymentJob extends ApplicationDeploymentJob
{
    public function __construct() {}
}

/**
 * @return array{0: Application, 1: Server, 2: InfisicalBinding}
 */
function makeInfisicalInjectionFixture(): array
{
    $team = Team::create([
        'name' => 'Infisical Injection Team',
        'personal_team' => false,
        'show_boarding' => false,
    ]);
    $project = Project::create([
        'name' => 'Infisical Injection Project',
        'team_id' => $team->id,
    ]);
    $environment = Environment::where('project_id', $project->id)->firstOrFail();
    $binding = InfisicalBinding::factory()->create([
        'environment_id' => $environment->id,
        'infisical_connection_id' => InfisicalConnection::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'build_pack' => 'dockerfile',
        'fqdn' => 'https://app.example.com',
    ]);
    $application->settings()->update([
        'is_env_sorting_enabled' => false,
        'include_source_commit_in_build' => false,
    ]);

    return [$application->fresh(), $server, $binding];
}

function makeInheritedSecret(InfisicalBinding $binding, string $key, string $value): void
{
    SharedEnvironmentVariable::create([
        'key' => $key,
        'value' => $value,
        'type' => 'environment',
        'team_id' => $binding->connection->team_id,
        'environment_id' => $binding->environment_id,
        'infisical_binding_id' => $binding->id,
    ]);
}

/**
 * @return array{0: InfisicalInjectionDeploymentJob, 1: ReflectionClass}
 */
function makeInfisicalInjectionJob(Application $application, Server $server, array $overrides = []): array
{
    $job = new InfisicalInjectionDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnNull();

    $properties = array_merge([
        'application' => $application->fresh(),
        'application_deployment_queue' => $queue,
        'build_pack' => $application->build_pack,
        'mainServer' => $server,
        'pull_request_id' => 0,
        'commit' => 'HEAD',
        'branch' => 'main',
        'container_name' => 'infisical-app',
        'workdir' => '/artifacts/infisical-app',
        'deployment_uuid' => 'deployment-uuid',
    ], $overrides);

    foreach ($properties as $property => $value) {
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($job, $value);
    }

    return [$job, $reflection];
}

function invokeInfisicalInjectionMethod(object $job, ReflectionClass $reflection, string $method): mixed
{
    $reflectionMethod = $reflection->getMethod($method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invoke($job);
}

test('the generated build-time environment inherits secrets and lets resource variables win', function () {
    [$application, $server, $binding] = makeInfisicalInjectionFixture();
    makeInheritedSecret($binding, 'API_KEY', 'from-infisical');
    makeInheritedSecret($binding, 'DB_PASSWORD', 'from-infisical');

    $application->environment_variables()->create([
        'key' => 'DB_PASSWORD',
        'value' => 'from-resource',
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    [$job, $reflection] = makeInfisicalInjectionJob($application, $server);
    $envs = invokeInfisicalInjectionMethod($job, $reflection, 'generate_buildtime_environment_variables');

    expect($envs)->toContain("API_KEY='from-infisical'")
        ->and($envs)->toContain('DB_PASSWORD="from-resource"')
        ->and($envs)->not->toContain("DB_PASSWORD='from-infisical'");
});

test('the generated runtime environment inherits secrets and lets resource variables win', function () {
    [$application, $server, $binding] = makeInfisicalInjectionFixture();
    makeInheritedSecret($binding, 'API_KEY', 'from-infisical');
    makeInheritedSecret($binding, 'DB_PASSWORD', 'from-infisical');

    $application->environment_variables()->create([
        'key' => 'DB_PASSWORD',
        'value' => 'from-resource',
        'is_runtime' => true,
        'is_buildtime' => false,
    ]);

    [$job, $reflection] = makeInfisicalInjectionJob($application, $server);
    $envs = invokeInfisicalInjectionMethod($job, $reflection, 'generate_runtime_environment_variables');

    expect($envs)->toContain('API_KEY=from-infisical');

    // Both lines are present; the resource-level one comes last, and the last line wins when sourced.
    expect($envs)->toContain('DB_PASSWORD=from-infisical')
        ->and($envs->last(fn (string $line) => str_starts_with($line, 'DB_PASSWORD=')))
        ->toBe('DB_PASSWORD=from-resource');
});

test('an inherited secret cannot override a coolify generated variable', function () {
    [$application, $server, $binding] = makeInfisicalInjectionFixture();
    makeInheritedSecret($binding, 'COOLIFY_BRANCH', 'from-infisical');

    [$job, $reflection] = makeInfisicalInjectionJob($application, $server);

    $runtime = invokeInfisicalInjectionMethod($job, $reflection, 'generate_runtime_environment_variables');
    expect($runtime->last(fn (string $line) => str_starts_with($line, 'COOLIFY_BRANCH=')))
        ->toBe('COOLIFY_BRANCH=main');

    [$job, $reflection] = makeInfisicalInjectionJob($application, $server);
    $buildtime = invokeInfisicalInjectionMethod($job, $reflection, 'generate_buildtime_environment_variables');
    expect($buildtime)->toContain("COOLIFY_BRANCH='main'")
        ->and($buildtime)->not->toContain("COOLIFY_BRANCH='from-infisical'");
});

test('inherited multiline and quoted values stay well formed in the runtime environment', function () {
    [$application, $server, $binding] = makeInfisicalInjectionFixture();
    $pem = "-----BEGIN KEY-----\nabc\n-----END KEY-----";
    makeInheritedSecret($binding, 'TLS_KEY', $pem);
    makeInheritedSecret($binding, 'QUOTED', 'a"b\'c\\d');

    [$job, $reflection] = makeInfisicalInjectionJob($application, $server);
    $envs = invokeInfisicalInjectionMethod($job, $reflection, 'generate_runtime_environment_variables');

    // A multiline value must be quoted as a single entry, never split across bare lines.
    expect($envs)->toContain("TLS_KEY='{$pem}'");

    // Quotes and backslashes go through the same escaper as resource-level variables.
    expect($envs)->toContain('QUOTED='.escapeEnvVariables('a"b\'c\\d'));
});
