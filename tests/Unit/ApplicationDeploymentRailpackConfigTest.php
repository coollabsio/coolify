<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

class TestableRailpackDeploymentJob extends ApplicationDeploymentJob
{
    public array $recordedCommands = [];

    public function __construct() {}

    public function execute_remote_command(...$commands)
    {
        $this->recordedCommands[] = $commands;
    }
}

function makeRailpackDeploymentJob(array $applicationAttributes = [], array $savedOutputs = []): array
{
    $job = new TestableRailpackDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    $application = new Application($applicationAttributes);

    foreach ([
        'application' => $application,
        'workdir' => '/artifacts/test-app',
        'deployment_uuid' => 'deployment-uuid',
        'saved_outputs' => new Collection($savedOutputs),
        'env_railpack_args' => "--env 'RAILPACK_NODE_VERSION=22'",
        'force_rebuild' => false,
        'addHosts' => '',
        'secrets_hash_key' => 'testing-app-key',
    ] as $property => $value) {
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($job, $value);
    }

    return [$job, $reflection];
}

function invokeRailpackMethod(object $job, ReflectionClass $reflection, string $method, array $arguments = []): mixed
{
    $reflectionMethod = $reflection->getMethod($method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($job, $arguments);
}

it('deep merges repository railpack config with coolify overrides', function () {
    $repositoryConfigJson = json_encode([
        '$schema' => 'https://schema.railpack.com',
        'packages' => [
            'node' => '20',
        ],
        'steps' => [
            'build' => [
                'inputs' => [['step' => 'install']],
                'commands' => ['npm run build'],
            ],
        ],
        'deploy' => [
            'variables' => [
                'NODE_ENV' => 'production',
            ],
            'startCommand' => 'node index.js',
        ],
    ], JSON_THROW_ON_ERROR);

    [$job, $reflection] = makeRailpackDeploymentJob(
        [
            'install_command' => 'npm ci',
            'build_command' => 'npm run build:prod',
            'start_command' => 'node server.js',
        ],
        [
            'railpack_config_exists' => 'exists',
            'railpack_repository_config' => $repositoryConfigJson,
        ],
    );

    $repositoryConfig = invokeRailpackMethod(
        $job,
        $reflection,
        'decode_railpack_config',
        [$repositoryConfigJson, 'repository railpack.json'],
    );
    $overrides = [
        'deploy' => [
            'variables' => [
                'APP_ENV' => 'production',
            ],
        ],
        'packages' => [
            'python' => '3.13',
        ],
    ];
    $generatedConfig = invokeRailpackMethod($job, $reflection, 'merge_railpack_config', [$repositoryConfig, $overrides]);

    expect($generatedConfig)->toMatchArray([
        '$schema' => 'https://schema.railpack.com',
        'packages' => [
            'node' => '20',
            'python' => '3.13',
        ],
        'steps' => [
            'build' => [
                'inputs' => [['step' => 'install']],
                'commands' => ['npm run build'],
            ],
        ],
        'deploy' => [
            'variables' => [
                'NODE_ENV' => 'production',
                'APP_ENV' => 'production',
            ],
            'startCommand' => 'node index.js',
        ],
    ]);
});

it('writes a generated railpack config file when repository config exists', function () {
    [$job, $reflection] = makeRailpackDeploymentJob(
        ['build_command' => 'npm run build'],
        [
            'railpack_config_exists' => 'exists',
            'railpack_repository_config' => json_encode([
                '$schema' => 'https://schema.railpack.com',
                'steps' => [
                    'build' => [
                        'commands' => ['npm run build'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ],
    );

    $configPath = invokeRailpackMethod($job, $reflection, 'generate_railpack_config_file');

    expect($configPath)->toBe('.coolify/railpack.generated.json');
    expect($job->recordedCommands)->toHaveCount(3);
});

it('does not generate a railpack config file for command overrides alone', function () {
    [$job, $reflection] = makeRailpackDeploymentJob([
        'install_command' => 'npm ci',
        'build_command' => 'npm run build',
        'start_command' => 'node server.js',
    ]);

    $configPath = invokeRailpackMethod($job, $reflection, 'generate_railpack_config_file');

    expect($configPath)->toBeNull();
    expect($job->recordedCommands)->toHaveCount(1);
});

it('fails fast when repository railpack config is invalid json', function () {
    [$job, $reflection] = makeRailpackDeploymentJob(
        ['build_command' => 'npm run build'],
        [
            'railpack_config_exists' => 'exists',
            'railpack_repository_config' => '{"steps":{"build":',
        ],
    );

    expect(fn () => invokeRailpackMethod($job, $reflection, 'generate_railpack_config_file'))
        ->toThrow(DeploymentException::class, 'Invalid repository railpack.json');
});

it('builds railpack prepare command using railpack env for install and cli flags for build/start overrides', function () {
    [$job, $reflection] = makeRailpackDeploymentJob(
        [
            'install_command' => 'npm ci',
            'build_command' => 'npm run build',
            'start_command' => 'node server.js',
        ],
    );
    $envRailpackArgsProperty = $reflection->getProperty('env_railpack_args');
    $envRailpackArgsProperty->setAccessible(true);
    $envRailpackArgsProperty->setValue($job, "--env 'RAILPACK_NODE_VERSION=22' --env 'RAILPACK_INSTALL_CMD=npm ci'");

    $command = invokeRailpackMethod(
        $job,
        $reflection,
        'railpack_prepare_command',
        ['.coolify/railpack.generated.json'],
    );

    expect($command)->toContain('railpack prepare');
    expect($command)->toContain("--env 'RAILPACK_NODE_VERSION=22'");
    expect($command)->toContain("--env 'RAILPACK_INSTALL_CMD=npm ci'");
    expect($command)->toContain('--build-cmd '.escapeshellarg('npm run build'));
    expect($command)->toContain('--start-cmd '.escapeshellarg('node server.js'));
    expect($command)->toContain('--config-file '.escapeshellarg('.coolify/railpack.generated.json'));
    expect($command)->toContain('--plan-out /artifacts/railpack-plan.json /artifacts/test-app');
    expect($command)->not->toContain("--env 'RAILPACK_BUILD_CMD=");
    expect($command)->not->toContain("--env 'RAILPACK_START_CMD=");
    expect($command)->not->toContain('RAILPACK_BUILD_CMD=');
    expect($command)->not->toContain('RAILPACK_START_CMD=');
});

it('fails fast when docker buildx is unavailable for railpack builds', function () {
    [$job, $reflection] = makeRailpackDeploymentJob();

    $dockerBuildxAvailableProperty = $reflection->getProperty('dockerBuildxAvailable');
    $dockerBuildxAvailableProperty->setAccessible(true);
    $dockerBuildxAvailableProperty->setValue($job, false);

    expect(fn () => invokeRailpackMethod($job, $reflection, 'ensure_docker_buildx_available_for_railpack'))
        ->toThrow(DeploymentException::class, 'Railpack deployments require the Docker buildx CLI plugin');
});

it('builds railpack docker command with matching env and secret flags for all railpack variables', function () {
    [$job, $reflection] = makeRailpackDeploymentJob([
        'uuid' => 'application-uuid',
    ]);

    $command = invokeRailpackMethod(
        $job,
        $reflection,
        'railpack_build_command',
        [
            'coollabsio/coolify:test',
            collect([
                'RAILPACK_NODE_VERSION' => '22',
                'RAILPACK_INSTALL_CMD' => 'npm ci && npm run postinstall',
                'RAILPACK_DEPLOY_APT_PACKAGES' => 'curl wget',
                'SECRET_JSON' => '{"token":"abc"}',
            ]),
        ],
    );

    expect($command)->toContain("env 'RAILPACK_NODE_VERSION=22'");
    expect($command)->toContain("'RAILPACK_INSTALL_CMD=npm ci && npm run postinstall'");
    expect($command)->toContain("'RAILPACK_DEPLOY_APT_PACKAGES=curl wget'");
    expect($command)->toContain("'SECRET_JSON={\"token\":\"abc\"}'");
    expect($command)->toContain("--secret 'id=RAILPACK_NODE_VERSION,env=RAILPACK_NODE_VERSION'");
    expect($command)->toContain("--secret 'id=RAILPACK_INSTALL_CMD,env=RAILPACK_INSTALL_CMD'");
    expect($command)->toContain("--secret 'id=RAILPACK_DEPLOY_APT_PACKAGES,env=RAILPACK_DEPLOY_APT_PACKAGES'");
    expect($command)->toContain("--secret 'id=SECRET_JSON,env=SECRET_JSON'");
    expect($command)->toContain(' --build-arg secrets-hash=');
    expect($command)->toContain('--build-arg BUILDKIT_SYNTAX="ghcr.io/railwayapp/railpack-frontend:v'.config('constants.coolify.railpack_version').'"');
});
