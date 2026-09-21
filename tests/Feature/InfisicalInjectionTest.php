<?php

use App\Actions\Infisical\ResolveInheritedSecrets;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InfisicalConnection;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A team whose Infisical connection is enabled, plus an environment under it.
 *
 * @return array{0: Team, 1: Environment}
 */
function infisicalEnabledTeam(): array
{
    $team = Team::factory()->create();
    InfisicalConnection::factory()->create(['team_id' => $team->id, 'is_enabled' => true]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return [$team, $environment];
}

test('resource level variables take precedence over inherited ones', function () {
    [$team, $environment] = infisicalEnabledTeam();
    makeInheritedSecret($team, $environment, 'DB_PASSWORD', 'from-infisical');

    $application = Application::factory()->create(['environment_id' => $environment->id]);
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
    [$team, $environment] = infisicalEnabledTeam();
    makeInheritedSecret($team, $environment, 'API_KEY', 'from-infisical');

    $application = Application::factory()->create(['environment_id' => $environment->id]);

    expect(ResolveInheritedSecrets::run($application)['API_KEY'])->toBe('from-infisical');
});

test('a team without an enabled connection inherits nothing', function () {
    $team = Team::factory()->create();
    InfisicalConnection::factory()->create(['team_id' => $team->id, 'is_enabled' => false]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    makeInheritedSecret($team, $environment, 'API_KEY', 'from-infisical');

    $application = Application::factory()->create(['environment_id' => $environment->id]);

    expect(ResolveInheritedSecrets::run($application))->toBeEmpty();
});

it('does not leak an environment scoped secret into a sibling environment', function () {
    $team = Team::factory()->create();
    InfisicalConnection::factory()->create(['team_id' => $team->id, 'is_enabled' => true]);
    $project = Project::factory()->create(['team_id' => $team->id]);

    $environmentA = Environment::factory()->create(['project_id' => $project->id]);
    $environmentB = Environment::factory()->create(['project_id' => $project->id]);

    makeInheritedSecret($team, $environmentA, 'SHARED_KEY', 'from-environment-a');
    makeInheritedSecret($team, $environmentB, 'SHARED_KEY', 'from-environment-b');
    makeInheritedSecret($team, $environmentA, 'ONLY_IN_A', 'a-only');

    $applicationB = Application::factory()->create(['environment_id' => $environmentB->id]);

    $resolved = ResolveInheritedSecrets::run($applicationB);

    expect($resolved['SHARED_KEY'])->toBe('from-environment-b')
        ->and($resolved)->not->toHaveKey('ONLY_IN_A');
});

it('lets an environment scoped secret override the project and team scoped ones', function () {
    $team = Team::factory()->create();
    InfisicalConnection::factory()->create(['team_id' => $team->id, 'is_enabled' => true]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    // Team- and project-scoped rows carry no environment_id: the unique index is
    // (key, environment_id, team_id), so three rows for one key can only coexist
    // when the lower-precedence ones are not pinned to the environment.
    makeInheritedSecret($team, $environment, 'TEAM_ONLY', 'from-team', [
        'type' => 'team',
        'environment_id' => null,
    ]);
    makeInheritedSecret($team, $environment, 'RANKED', 'from-team', [
        'type' => 'team',
        'environment_id' => null,
    ]);
    makeInheritedSecret($team, $environment, 'RANKED', 'from-project', [
        'type' => 'project',
        'project_id' => $project->id,
        'environment_id' => null,
    ]);
    makeInheritedSecret($team, $environment, 'RANKED', 'from-environment');

    $application = Application::factory()->create(['environment_id' => $environment->id]);

    $resolved = ResolveInheritedSecrets::run($application);

    expect($resolved['RANKED'])->toBe('from-environment')
        ->and($resolved['TEAM_ONLY'])->toBe('from-team');
});

it('does not inherit a secret belonging to another project in the same team', function () {
    $team = Team::factory()->create();
    InfisicalConnection::factory()->create(['team_id' => $team->id, 'is_enabled' => true]);

    $projectA = Project::factory()->create(['team_id' => $team->id]);
    $environmentA = Environment::factory()->create(['project_id' => $projectA->id]);
    $projectB = Project::factory()->create(['team_id' => $team->id]);
    $environmentB = Environment::factory()->create(['project_id' => $projectB->id]);

    makeInheritedSecret($team, $environmentA, 'PROJECT_A_KEY', 'a-only', [
        'type' => 'project',
        'project_id' => $projectA->id,
        'environment_id' => null,
    ]);

    $applicationB = Application::factory()->create(['environment_id' => $environmentB->id]);

    expect(ResolveInheritedSecrets::run($applicationB))->not->toHaveKey('PROJECT_A_KEY');
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
 * @return array{0: Application, 1: Server, 2: Team, 3: Environment}
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
    InfisicalConnection::factory()->create(['team_id' => $team->id, 'is_enabled' => true]);
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

    return [$application->fresh(), $server, $team, $environment];
}

function makeInheritedSecret(Team $team, Environment $environment, string $key, string $value, array $overrides = []): void
{
    SharedEnvironmentVariable::create(array_merge([
        'key' => $key,
        'value' => $value,
        'type' => 'environment',
        'team_id' => $team->id,
        'environment_id' => $environment->id,
        'is_infisical_managed' => true,
        'infisical_path' => '/',
    ], $overrides));
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
    [$application, $server, $team, $environment] = makeInfisicalInjectionFixture();
    makeInheritedSecret($team, $environment, 'API_KEY', 'from-infisical');
    makeInheritedSecret($team, $environment, 'DB_PASSWORD', 'from-infisical');

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
    [$application, $server, $team, $environment] = makeInfisicalInjectionFixture();
    makeInheritedSecret($team, $environment, 'API_KEY', 'from-infisical');
    makeInheritedSecret($team, $environment, 'DB_PASSWORD', 'from-infisical');

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
    [$application, $server, $team, $environment] = makeInfisicalInjectionFixture();
    makeInheritedSecret($team, $environment, 'COOLIFY_BRANCH', 'from-infisical');

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
    [$application, $server, $team, $environment] = makeInfisicalInjectionFixture();
    $pem = "-----BEGIN KEY-----\nabc\n-----END KEY-----";
    makeInheritedSecret($team, $environment, 'TLS_KEY', $pem);
    makeInheritedSecret($team, $environment, 'QUOTED', 'a"b\'c\\d');

    [$job, $reflection] = makeInfisicalInjectionJob($application, $server);
    $envs = invokeInfisicalInjectionMethod($job, $reflection, 'generate_runtime_environment_variables');

    // A multiline value must be quoted as a single entry, never split across bare lines.
    expect($envs)->toContain("TLS_KEY='{$pem}'");

    // Quotes and backslashes go through the same escaper as resource-level variables.
    expect($envs)->toContain('QUOTED='.escapeEnvVariables('a"b\'c\\d'));
});
