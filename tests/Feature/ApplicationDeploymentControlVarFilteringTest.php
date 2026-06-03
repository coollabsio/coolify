<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

class TestableControlVarFilteringDeploymentJob extends ApplicationDeploymentJob
{
    public array $recordedCommands = [];

    public ?string $writtenDockerfile = null;

    public function __construct() {}

    public function execute_remote_command(...$commands)
    {
        $this->recordedCommands[] = $commands;

        foreach ($commands as $command) {
            $commandString = is_array($command) ? ($command['command'] ?? $command[0] ?? null) : $command;

            if (! is_string($commandString)) {
                continue;
            }

            if (preg_match('/echo .*?([A-Za-z0-9+\\/=]{16,}).*?\\| base64 -d \\| tee \\/artifacts\\/test-app\\/Dockerfile > \\/dev\\/null/', $commandString, $matches) === 1) {
                $this->writtenDockerfile = base64_decode($matches[1]) ?: null;
            }
        }
    }
}

function makeDeploymentControlVarFixture(array $applicationAttributes = []): array
{
    $team = Team::create([
        'name' => 'Control Var Team',
        'description' => 'Team for deployment control var tests.',
        'personal_team' => false,
        'show_boarding' => false,
    ]);
    $project = Project::create([
        'name' => 'Control Var Project',
        'team_id' => $team->id,
    ]);
    $environment = Environment::where('project_id', $project->id)->firstOrFail();
    $server = Server::factory()->create([
        'team_id' => $team->id,
    ]);

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'build_pack' => 'dockerfile',
        ...$applicationAttributes,
    ]);

    $application->settings()->update([
        'inject_build_args_to_dockerfile' => true,
        'include_source_commit_in_build' => false,
        'is_env_sorting_enabled' => false,
    ]);

    return [$application->fresh(), $server];
}

function createApplicationEnvironmentVariable(Application $application, array $attributes): EnvironmentVariable
{
    return EnvironmentVariable::create([
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
        'is_preview' => false,
        'is_runtime' => true,
        'is_buildtime' => true,
        'is_multiline' => false,
        'is_literal' => false,
        ...$attributes,
    ]);
}

function makeControlVarFilteringJob(Application $application, Server $server, array $overrides = []): array
{
    $job = new TestableControlVarFilteringDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnNull();

    $properties = [
        'application' => $application->fresh(),
        'application_deployment_queue' => $queue,
        'build_pack' => $application->build_pack,
        'mainServer' => $server,
        'pull_request_id' => 0,
        'commit' => 'HEAD',
        'workdir' => '/artifacts/test-app',
        'deployment_uuid' => 'deployment-uuid',
        'dockerfile_location' => '/Dockerfile',
        'container_name' => 'control-var-app',
        'coolify_variables' => null,
        'dockerSecretsSupported' => false,
    ];

    $mergedProperties = array_merge($properties, $overrides);
    $mergedProperties['saved_outputs'] = new Collection($overrides['saved_outputs'] ?? []);

    if (($mergedProperties['pull_request_id'] ?? 0) !== 0 && ! array_key_exists('preview', $mergedProperties)) {
        $mergedProperties['preview'] = ApplicationPreview::create([
            'application_id' => $application->id,
            'pull_request_id' => $mergedProperties['pull_request_id'],
            'pull_request_html_url' => 'https://example.com/pr/'.$mergedProperties['pull_request_id'],
            'fqdn' => 'https://preview.example.com',
        ]);
    }

    foreach ($mergedProperties as $property => $value) {
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($job, $value);
    }

    return [$job, $reflection];
}

function invokeDeploymentJobMethod(object $job, ReflectionClass $reflection, string $method): mixed
{
    $reflectionMethod = $reflection->getMethod($method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invoke($job);
}

function readDeploymentJobProperty(object $job, ReflectionClass $reflection, string $property): mixed
{
    $reflectionProperty = $reflection->getProperty($property);
    $reflectionProperty->setAccessible(true);

    return $reflectionProperty->getValue($job);
}

it('filters buildpack control vars from generic build args', function () {
    [$application, $server] = makeDeploymentControlVarFixture();

    createApplicationEnvironmentVariable($application, [
        'key' => 'APP_ENV',
        'value' => 'production',
    ]);
    createApplicationEnvironmentVariable($application, [
        'key' => 'NIXPACKS_NODE_VERSION',
        'value' => '22',
    ]);
    createApplicationEnvironmentVariable($application, [
        'key' => 'RAILPACK_NODE_VERSION',
        'value' => '20',
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server);

    invokeDeploymentJobMethod($job, $reflection, 'generate_env_variables');

    /** @var Collection $envArgs */
    $envArgs = readDeploymentJobProperty($job, $reflection, 'env_args');

    expect($envArgs->get('APP_ENV'))->toBe('production');
    expect($envArgs->has('NIXPACKS_NODE_VERSION'))->toBeFalse();
    expect($envArgs->has('RAILPACK_NODE_VERSION'))->toBeFalse();
});

it('filters buildpack control vars from preview build-time env files', function () {
    [$application, $server] = makeDeploymentControlVarFixture();

    createApplicationEnvironmentVariable($application, [
        'key' => 'APP_ENV',
        'value' => 'production',
        'is_preview' => true,
    ]);
    createApplicationEnvironmentVariable($application, [
        'key' => 'NIXPACKS_NODE_VERSION',
        'value' => '22',
        'is_preview' => true,
    ]);
    createApplicationEnvironmentVariable($application, [
        'key' => 'RAILPACK_NODE_VERSION',
        'value' => '20',
        'is_preview' => true,
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server, [
        'pull_request_id' => 42,
    ]);

    /** @var Collection $buildtimeEnvs */
    $buildtimeEnvs = invokeDeploymentJobMethod($job, $reflection, 'generate_buildtime_environment_variables');

    expect($buildtimeEnvs->contains(fn (string $env) => str($env)->startsWith('APP_ENV=')))->toBeTrue();
    expect($buildtimeEnvs->contains(fn (string $env) => str($env)->startsWith('NIXPACKS_NODE_VERSION=')))->toBeFalse();
    expect($buildtimeEnvs->contains(fn (string $env) => str($env)->startsWith('RAILPACK_NODE_VERSION=')))->toBeFalse();
});

it('does not let preview docker compose service names override generated build-time service names', function () {
    $compose = <<<'YAML'
services:
  app:
    image: nginx
  postgresapp:
    image: postgres:16-alpine
YAML;

    [$application, $server] = makeDeploymentControlVarFixture([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $compose,
        'docker_compose' => $compose,
        'docker_compose_domains' => '[]',
    ]);

    createApplicationEnvironmentVariable($application, [
        'key' => 'SERVICE_NAME_POSTGRESAPP',
        'value' => '',
        'is_preview' => true,
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    createApplicationEnvironmentVariable($application, [
        'key' => 'SERVICE_URL_APP',
        'value' => '',
        'is_preview' => true,
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server, [
        'pull_request_id' => 241,
    ]);

    /** @var Collection $buildtimeEnvs */
    $buildtimeEnvs = invokeDeploymentJobMethod($job, $reflection, 'generate_buildtime_environment_variables');
    $envString = $buildtimeEnvs->implode("\n");

    expect($envString)->toContain("SERVICE_NAME_POSTGRESAPP='postgresapp-pr-241'");
    expect($envString)->not->toContain('SERVICE_NAME_POSTGRESAPP=""');
    expect($envString)->not->toContain('SERVICE_URL_APP=');
});

it('does not let production docker compose service names override generated build-time service names', function () {
    $compose = <<<'YAML'
services:
  app:
    image: nginx
  postgresapp:
    image: postgres:16-alpine
YAML;

    [$application, $server] = makeDeploymentControlVarFixture([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $compose,
        'docker_compose' => $compose,
        'docker_compose_domains' => '[]',
    ]);

    createApplicationEnvironmentVariable($application, [
        'key' => 'SERVICE_NAME_POSTGRESAPP',
        'value' => 'stale-postgresapp',
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server);

    /** @var Collection $buildtimeEnvs */
    $buildtimeEnvs = invokeDeploymentJobMethod($job, $reflection, 'generate_buildtime_environment_variables');
    $envString = $buildtimeEnvs->implode("\n");

    expect($envString)->toContain("SERVICE_NAME_POSTGRESAPP='postgresapp'");
    expect($envString)->not->toContain('stale-postgresapp');
});

it('filters docker compose generated service variables from build args', function () {
    [$application, $server] = makeDeploymentControlVarFixture([
        'build_pack' => 'dockercompose',
    ]);

    createApplicationEnvironmentVariable($application, [
        'key' => 'APP_ENV',
        'value' => 'production',
        'is_preview' => true,
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    createApplicationEnvironmentVariable($application, [
        'key' => 'SERVICE_NAME_POSTGRESAPP',
        'value' => '',
        'is_preview' => true,
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    createApplicationEnvironmentVariable($application, [
        'key' => 'SERVICE_URL_APP',
        'value' => 'https://preview.example.com',
        'is_preview' => true,
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server, [
        'pull_request_id' => 241,
    ]);

    invokeDeploymentJobMethod($job, $reflection, 'generate_env_variables');

    /** @var Collection $envArgs */
    $envArgs = readDeploymentJobProperty($job, $reflection, 'env_args');

    expect($envArgs->get('APP_ENV'))->toBe('production');
    expect($envArgs->has('SERVICE_NAME_POSTGRESAPP'))->toBeFalse();
    expect($envArgs->has('SERVICE_URL_APP'))->toBeFalse();
});

it('filters buildpack control vars from preview runtime env fallback', function () {
    [$application, $server] = makeDeploymentControlVarFixture();

    createApplicationEnvironmentVariable($application, [
        'key' => 'APP_NAME',
        'value' => 'coolify',
        'is_runtime' => true,
        'is_buildtime' => false,
    ]);
    createApplicationEnvironmentVariable($application, [
        'key' => 'NIXPACKS_NODE_VERSION',
        'value' => '22',
        'is_runtime' => true,
        'is_buildtime' => false,
    ]);
    createApplicationEnvironmentVariable($application, [
        'key' => 'RAILPACK_NODE_VERSION',
        'value' => '20',
        'is_runtime' => true,
        'is_buildtime' => false,
    ]);
    createApplicationEnvironmentVariable($application, [
        'key' => 'PREVIEW_FLAG',
        'value' => 'enabled',
        'is_preview' => true,
        'is_runtime' => true,
        'is_buildtime' => false,
    ]);

    $application->environment_variables_preview()
        ->whereIn('key', ['APP_NAME', 'NIXPACKS_NODE_VERSION', 'RAILPACK_NODE_VERSION'])
        ->delete();

    [$job, $reflection] = makeControlVarFilteringJob($application, $server, [
        'pull_request_id' => 99,
    ]);

    /** @var Collection $runtimeEnvs */
    $runtimeEnvs = invokeDeploymentJobMethod($job, $reflection, 'generate_runtime_environment_variables');

    expect($runtimeEnvs->contains(fn (string $env) => str($env)->startsWith('APP_NAME=')))->toBeTrue();
    expect($runtimeEnvs->contains(fn (string $env) => str($env)->startsWith('PREVIEW_FLAG=')))->toBeTrue();
    expect($runtimeEnvs->contains(fn (string $env) => str($env)->startsWith('NIXPACKS_NODE_VERSION=')))->toBeFalse();
    expect($runtimeEnvs->contains(fn (string $env) => str($env)->startsWith('RAILPACK_NODE_VERSION=')))->toBeFalse();
});

it('filters buildpack control vars from dockerfile arg injection', function () {
    [$application, $server] = makeDeploymentControlVarFixture();

    createApplicationEnvironmentVariable($application, [
        'key' => 'APP_ENV',
        'value' => 'production',
        'is_runtime' => false,
        'is_buildtime' => true,
    ]);
    createApplicationEnvironmentVariable($application, [
        'key' => 'NIXPACKS_NODE_VERSION',
        'value' => '22',
        'is_runtime' => false,
        'is_buildtime' => true,
    ]);
    createApplicationEnvironmentVariable($application, [
        'key' => 'RAILPACK_NODE_VERSION',
        'value' => '20',
        'is_runtime' => false,
        'is_buildtime' => true,
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server, [
        'saved_outputs' => [
            'dockerfile' => "FROM php:8.4-cli\nRUN php -v",
        ],
    ]);

    invokeDeploymentJobMethod($job, $reflection, 'add_build_env_variables_to_dockerfile');

    expect($job->writtenDockerfile)->toContain('ARG APP_ENV=production');
    expect($job->writtenDockerfile)->not->toContain('ARG NIXPACKS_NODE_VERSION=');
    expect($job->writtenDockerfile)->not->toContain('ARG RAILPACK_NODE_VERSION=');
});

it('builds railpack variables from generic buildtime vars railpack vars and coolify vars only', function () {
    [$application, $server] = makeDeploymentControlVarFixture([
        'build_pack' => 'railpack',
        'fqdn' => 'https://railpack.example.com',
        'install_command' => 'pnpm install --frozen-lockfile',
    ]);

    createApplicationEnvironmentVariable($application, [
        'key' => 'APP_ENV',
        'value' => 'production',
        'is_runtime' => false,
        'is_buildtime' => true,
    ]);
    createApplicationEnvironmentVariable($application, [
        'key' => 'RUNTIME_ONLY',
        'value' => 'runtime',
        'is_runtime' => true,
        'is_buildtime' => false,
    ]);
    createApplicationEnvironmentVariable($application, [
        'key' => 'NIXPACKS_NODE_VERSION',
        'value' => '22',
        'is_runtime' => false,
        'is_buildtime' => true,
    ]);
    createApplicationEnvironmentVariable($application, [
        'key' => 'RAILPACK_NODE_VERSION',
        'value' => '20',
        'is_runtime' => false,
        'is_buildtime' => true,
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application->fresh(), $server, [
        'build_pack' => 'railpack',
        'branch' => 'main',
    ]);

    /** @var Collection $variables */
    $variables = invokeDeploymentJobMethod($job, $reflection, 'railpack_build_variables');

    expect($variables->get('APP_ENV'))->toBe('production');
    expect($variables->get('RAILPACK_NODE_VERSION'))->toBe('20');
    expect($variables->get('RAILPACK_INSTALL_CMD'))->toBe('pnpm install --frozen-lockfile');
    expect($variables->get('RAILPACK_DEPLOY_APT_PACKAGES'))->toBe('curl wget');
    expect($variables->get('COOLIFY_RESOURCE_UUID'))->toBe($application->uuid);
    expect($variables->has('NIXPACKS_NODE_VERSION'))->toBeFalse();
    expect($variables->has('RUNTIME_ONLY'))->toBeFalse();
});

it('builds preview railpack variables without leaking stale nixpacks vars', function () {
    [$application, $server] = makeDeploymentControlVarFixture([
        'build_pack' => 'railpack',
        'fqdn' => 'https://railpack.example.com',
    ]);

    createApplicationEnvironmentVariable($application, [
        'key' => 'PREVIEW_BUILD_FLAG',
        'value' => 'enabled',
        'is_preview' => true,
        'is_runtime' => false,
        'is_buildtime' => true,
    ]);
    createApplicationEnvironmentVariable($application, [
        'key' => 'PREVIEW_RUNTIME_ONLY',
        'value' => 'runtime',
        'is_preview' => true,
        'is_runtime' => true,
        'is_buildtime' => false,
    ]);
    createApplicationEnvironmentVariable($application, [
        'key' => 'NIXPACKS_NODE_VERSION',
        'value' => '22',
        'is_preview' => true,
        'is_runtime' => false,
        'is_buildtime' => true,
    ]);
    createApplicationEnvironmentVariable($application, [
        'key' => 'RAILPACK_NODE_VERSION',
        'value' => '20',
        'is_preview' => true,
        'is_runtime' => false,
        'is_buildtime' => true,
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application->fresh(), $server, [
        'build_pack' => 'railpack',
        'branch' => 'feature/railpack',
        'pull_request_id' => 123,
    ]);

    /** @var Collection $variables */
    $variables = invokeDeploymentJobMethod($job, $reflection, 'railpack_build_variables');

    expect($variables->get('PREVIEW_BUILD_FLAG'))->toBe('enabled');
    expect($variables->get('RAILPACK_NODE_VERSION'))->toBe('20');
    expect($variables->get('RAILPACK_DEPLOY_APT_PACKAGES'))->toBe('curl wget');
    expect($variables->get('COOLIFY_RESOURCE_UUID'))->toBe($application->uuid);
    expect($variables->has('NIXPACKS_NODE_VERSION'))->toBeFalse();
    expect($variables->has('PREVIEW_RUNTIME_ONLY'))->toBeFalse();
});
