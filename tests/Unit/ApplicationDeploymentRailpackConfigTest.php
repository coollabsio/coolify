<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use App\Models\EnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

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

it('checks buildx inside the helper container before railpack builds', function () {
    [$job, $reflection] = makeRailpackDeploymentJob([], [
        'railpack_helper_buildx_available' => 'available',
    ]);

    invokeRailpackMethod($job, $reflection, 'ensure_helper_docker_buildx_available_for_railpack');

    expect($job->recordedCommands[0][0][0])
        ->toContain('DOCKER_CONFIG=/root/.docker docker buildx version');
});

it('fails clearly when buildx is missing inside the helper container', function () {
    [$job, $reflection] = makeRailpackDeploymentJob([], [
        'railpack_helper_buildx_available' => 'not-available',
    ]);

    expect(fn () => invokeRailpackMethod($job, $reflection, 'ensure_helper_docker_buildx_available_for_railpack'))
        ->toThrow(DeploymentException::class, 'helper container');
});

it('pins docker config while running railpack buildx commands', function () {
    [$job, $reflection] = makeRailpackDeploymentJob([
        'uuid' => 'application-uuid',
    ]);

    $command = invokeRailpackMethod(
        $job,
        $reflection,
        'railpack_build_command',
        [
            'coollabsio/coolify:test',
            collect([]),
        ],
    );

    expect($command)
        ->toContain('DOCKER_CONFIG=/root/.docker docker buildx create --name coolify-railpack')
        ->toContain('DOCKER_CONFIG=/root/.docker docker buildx build --builder coolify-railpack');
});

it('filters reserved docker client variables from railpack build secrets', function () {
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
                'DOCKER_CONFIG' => '/tmp/no-buildx',
                'DOCKER_HOST' => 'tcp://invalid:2375',
                'RAILPACK_NODE_VERSION' => '22',
                'APP_ENV' => 'production',
            ]),
        ],
    );

    expect($command)
        ->toContain("--secret 'id=RAILPACK_NODE_VERSION,env=RAILPACK_NODE_VERSION'")
        ->toContain("--secret 'id=APP_ENV,env=APP_ENV'")
        ->not->toContain('id=DOCKER_CONFIG')
        ->not->toContain('id=DOCKER_HOST')
        ->not->toContain("env 'DOCKER_CONFIG=")
        ->not->toContain("env 'DOCKER_HOST=");
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

    // Build-time variables are interpolated by sourcing the build-time .env file before
    // the build, so user/Coolify variables must NOT be forwarded inline as literals.
    expect($command)->toContain('set -a && source /artifacts/build-time.env && set +a');
    expect($command)->toContain("env 'RAILPACK_NODE_VERSION=22'");
    expect($command)->toContain("'RAILPACK_INSTALL_CMD=npm ci && npm run postinstall'");
    expect($command)->toContain("'RAILPACK_DEPLOY_APT_PACKAGES=curl wget'");
    // SECRET_JSON is not a buildpack control variable, so it is provided via the sourced
    // build-time .env file (which supports $VAR interpolation) rather than inline `env`.
    expect($command)->not->toContain("'SECRET_JSON={\"token\":\"abc\"}'");
    expect($command)->toContain("--secret 'id=RAILPACK_NODE_VERSION,env=RAILPACK_NODE_VERSION'");
    expect($command)->toContain("--secret 'id=RAILPACK_INSTALL_CMD,env=RAILPACK_INSTALL_CMD'");
    expect($command)->toContain("--secret 'id=RAILPACK_DEPLOY_APT_PACKAGES,env=RAILPACK_DEPLOY_APT_PACKAGES'");
    expect($command)->toContain("--secret 'id=SECRET_JSON,env=SECRET_JSON'");
    expect($command)->toContain(' --build-arg secrets-hash=');
    expect($command)->toContain('--build-arg BUILDKIT_SYNTAX="ghcr.io/railwayapp/railpack-frontend:v'.config('constants.coolify.railpack_version').'"');
});

it('interpolates build-time variable references for railpack by sourcing the build-time env file', function () {
    [$job, $reflection] = makeRailpackDeploymentJob([
        'uuid' => 'application-uuid',
    ]);

    // Mirrors the issue: BETTER_AUTH_URL=$COOLIFY_URL must be interpolated at build time.
    $command = invokeRailpackMethod(
        $job,
        $reflection,
        'railpack_build_command',
        [
            'coollabsio/coolify:test',
            collect([
                'BETTER_AUTH_URL' => '$COOLIFY_URL',
                'COOLIFY_URL' => 'https://sapere-10.bobman.dev',
            ]),
        ],
    );

    // The literal `$COOLIFY_URL` must NOT be forwarded inline; it is resolved by the shell
    // after sourcing the build-time .env file, then read through the build secret.
    expect($command)->toContain('set -a && source /artifacts/build-time.env && set +a');
    expect($command)->not->toContain("'BETTER_AUTH_URL=\$COOLIFY_URL'");
    expect($command)->not->toContain("env 'BETTER_AUTH_URL");
    expect($command)->toContain("--secret 'id=BETTER_AUTH_URL,env=BETTER_AUTH_URL'");
    expect($command)->toContain("--secret 'id=COOLIFY_URL,env=COOLIFY_URL'");
});

it('creates an empty build-time env file for railpack when there are no generated build-time variables', function () {
    [$job, $reflection] = makeRailpackDeploymentJob([
        'build_pack' => 'railpack',
        'compose_parsing_version' => '3',
    ]);

    $applicationProperty = $reflection->getProperty('application');
    $applicationProperty->setAccessible(true);
    $application = $applicationProperty->getValue($job);
    $application->setRelation('settings', new ApplicationSetting([
        'include_source_commit_in_build' => false,
        'is_env_sorting_enabled' => false,
    ]));
    $application->setRelation('environment_variables', collect([
        new EnvironmentVariable(['key' => 'COOLIFY_FQDN']),
        new EnvironmentVariable(['key' => 'COOLIFY_URL']),
        new EnvironmentVariable(['key' => 'COOLIFY_BRANCH']),
        new EnvironmentVariable(['key' => 'COOLIFY_RESOURCE_UUID']),
    ]));

    foreach ([
        'application_deployment_queue' => new class extends ApplicationDeploymentQueue
        {
            public function addLogEntry(string $message, string $type = 'stdout', bool $hidden = false): void {}
        },
        'build_pack' => 'railpack',
        'pull_request_id' => 0,
    ] as $property => $value) {
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($job, $value);
    }

    invokeRailpackMethod($job, $reflection, 'save_buildtime_environment_variables');

    expect(collect($job->recordedCommands)->flatten()->implode(' '))->toContain('touch /artifacts/build-time.env');
});
