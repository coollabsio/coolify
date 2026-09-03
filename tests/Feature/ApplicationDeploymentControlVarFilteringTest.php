<?php

use App\Exceptions\DeploymentException;
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
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

class TestableControlVarFilteringDeploymentJob extends ApplicationDeploymentJob
{
    public array $recordedCommands = [];

    public array $recordedLogEntries = [];

    public array $writtenArtifacts = [];

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

            if (preg_match('~echo .*?([A-Za-z0-9+/=]{8,}).*?\\| base64 -d \\| tee (/artifacts/[^ ]+) > /dev/null~', $commandString, $matches) === 1) {
                $this->writtenArtifacts[$matches[2]] = base64_decode($matches[1]);
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
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function (string $message, string $type = 'stdout', bool $hidden = false) use ($job) {
        $job->recordedLogEntries[] = $message;

        return null;
    });

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

function invokeDeploymentJobMethod(object $job, ReflectionClass $reflection, string $method, mixed ...$arguments): mixed
{
    $reflectionMethod = $reflection->getMethod($method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invoke($job, ...$arguments);
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

it('rejects unsafe Nixpacks plan variable keys before writing the build-time env file', function (string $key) {
    [$application, $server] = makeDeploymentControlVarFixture([
        'build_pack' => 'nixpacks',
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server, [
        'nixpacks_plan_json' => collect([
            'variables' => [
                $key => 'value',
            ],
        ]),
    ]);

    expect(fn () => invokeDeploymentJobMethod($job, $reflection, 'generate_buildtime_environment_variables'))
        ->toThrow(DeploymentException::class);
})->with([
    'command substitution' => 'X$(id)',
    'backticks' => 'X`id`',
    'newline' => "X\nid",
    'shell assignment' => 'X=value',
    'semicolon' => 'X;id',
    'pipe' => 'X|id',
    'ampersand' => 'X&id',
    'leading dollar' => '$(id)',
    'command substitution with arguments' => 'X$(docker run --rm -v /:/mnt alpine true)',
]);

it('keeps persisted dotted user build-time variable keys', function () {
    [$application, $server] = makeDeploymentControlVarFixture();

    createApplicationEnvironmentVariable($application, [
        'key' => 'X.VALUE',
        'value' => 'unsafe',
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server);

    /** @var Collection $buildtimeEnvs */
    $buildtimeEnvs = invokeDeploymentJobMethod($job, $reflection, 'generate_buildtime_environment_variables');

    expect($buildtimeEnvs)->toContain('X.VALUE="unsafe"');
});

it('loads shell variables and passes dotted variables through the build-time environment launcher', function () {
    $temporaryDirectory = sys_get_temp_dir().'/coolify-build-env-'.str()->random(8);
    expect(mkdir($temporaryDirectory))->toBeTrue();
    $shellEnvironmentPath = $temporaryDirectory.'/build-time-shell.env';
    $launcherPath = $temporaryDirectory.'/run-with-build-time-env';
    $injectionMarkerPath = $temporaryDirectory.'/injection-marker';

    try {
        [$application, $server] = makeDeploymentControlVarFixture();

        createApplicationEnvironmentVariable($application, [
            'key' => 'BASE_VALUE',
            'value' => 'expanded',
        ]);
        createApplicationEnvironmentVariable($application, [
            'key' => 'X.VALUE',
            'value' => '$BASE_VALUE',
        ]);
        createApplicationEnvironmentVariable($application, [
            'key' => 'DOTTED.VALUE',
            'value' => "$(touch {$injectionMarkerPath})",
        ]);

        [$job, $reflection] = makeControlVarFilteringJob($application, $server);

        invokeDeploymentJobMethod($job, $reflection, 'save_buildtime_environment_variables');

        expect($job->writtenArtifacts[ApplicationDeploymentJob::BUILD_TIME_ENV_PATH])
            ->toContain('BASE_VALUE="expanded"')
            ->toContain('X.VALUE="$BASE_VALUE"');
        expect($job->writtenArtifacts[ApplicationDeploymentJob::BUILD_TIME_SHELL_ENV_PATH])
            ->toContain('BASE_VALUE="expanded"')
            ->not->toContain('X.VALUE');
        expect($job->writtenArtifacts[ApplicationDeploymentJob::BUILD_TIME_ENV_LAUNCHER_PATH])
            ->toContain('source '.ApplicationDeploymentJob::BUILD_TIME_SHELL_ENV_PATH)
            ->toContain('X.VALUE="$BASE_VALUE"')
            ->toContain('exec env');

        $wrappedCommand = invokeDeploymentJobMethod($job, $reflection, 'wrap_build_command_with_env_export', 'printenv X.VALUE');

        expect($wrappedCommand)
            ->toContain(ApplicationDeploymentJob::BUILD_TIME_ENV_LAUNCHER_PATH)
            ->toContain("/bin/bash -c 'printenv X.VALUE'")
            ->not->toContain('source '.ApplicationDeploymentJob::BUILD_TIME_ENV_PATH);

        file_put_contents($shellEnvironmentPath, $job->writtenArtifacts[ApplicationDeploymentJob::BUILD_TIME_SHELL_ENV_PATH]);
        file_put_contents(
            $launcherPath,
            str_replace(
                'source '.ApplicationDeploymentJob::BUILD_TIME_SHELL_ENV_PATH,
                'source '.$shellEnvironmentPath,
                $job->writtenArtifacts[ApplicationDeploymentJob::BUILD_TIME_ENV_LAUNCHER_PATH],
            ),
        );
        chmod($launcherPath, 0700);

        $process = new Process(['/bin/bash', $launcherPath, '/bin/bash', '-c', 'printenv X.VALUE; printenv DOTTED.VALUE']);
        $process->mustRun();

        expect($process->getOutput())
            ->toContain("expanded\n")
            ->toContain("$(touch {$injectionMarkerPath})");
        expect(file_exists($injectionMarkerPath))->toBeFalse();
    } finally {
        @unlink($launcherPath);
        @unlink($shellEnvironmentPath);
        @unlink($injectionMarkerPath);
        @rmdir($temporaryDirectory);
    }
});

it('keeps the original sourced environment path when build-time keys are shell safe', function () {
    [$application, $server] = makeDeploymentControlVarFixture();

    createApplicationEnvironmentVariable($application, [
        'key' => 'SAFE_VALUE',
        'value' => 'safe',
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server);

    invokeDeploymentJobMethod($job, $reflection, 'save_buildtime_environment_variables');

    expect($job->writtenArtifacts)
        ->toHaveKey(ApplicationDeploymentJob::BUILD_TIME_ENV_PATH)
        ->not->toHaveKey(ApplicationDeploymentJob::BUILD_TIME_SHELL_ENV_PATH)
        ->not->toHaveKey(ApplicationDeploymentJob::BUILD_TIME_ENV_LAUNCHER_PATH);

    $wrappedCommand = invokeDeploymentJobMethod($job, $reflection, 'wrap_build_command_with_env_export', 'docker build .');

    expect($wrappedCommand)
        ->toContain('set -a && source '.ApplicationDeploymentJob::BUILD_TIME_ENV_PATH.' && set +a && docker build .')
        ->not->toContain(ApplicationDeploymentJob::BUILD_TIME_ENV_LAUNCHER_PATH);
});

it('uses BuildKit secrets for dotted Nixpacks variables instead of invalid Dockerfile expansion', function () {
    [$application, $server] = makeDeploymentControlVarFixture([
        'build_pack' => 'nixpacks',
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server, [
        'dockerBuildkitSupported' => true,
        'dockerSecretsAvailable' => true,
        'env_args' => collect(['X.VALUE' => 'dotted-buildtime-ok']),
        'nixpacks_plan_json' => collect([
            'variables' => ['X.VALUE' => 'dotted-buildtime-ok'],
        ]),
        'saved_outputs' => collect([
            'dockerfile_content' => "FROM alpine\nARG SAFE X.VALUE\nENV SAFE=\$SAFE X.VALUE=\$X.VALUE\nRUN printenv X.VALUE",
        ]),
    ]);

    invokeDeploymentJobMethod($job, $reflection, 'generate_build_env_variables');

    expect(readDeploymentJobProperty($job, $reflection, 'dockerSecretsSupported'))->toBeTrue();
    expect(readDeploymentJobProperty($job, $reflection, 'build_secrets'))->toContain('--secret id=X.VALUE,env=X.VALUE');

    invokeDeploymentJobMethod($job, $reflection, 'modify_dockerfile_for_secrets', '/artifacts/test-app/.nixpacks/Dockerfile');

    $dockerfile = $job->writtenArtifacts['/artifacts/test-app/.nixpacks/Dockerfile'];

    expect($dockerfile)
        ->not->toContain('ARG X.VALUE')
        ->not->toContain('X.VALUE=$X.VALUE')
        ->toContain('ARG SAFE')
        ->toContain('ENV SAFE=$SAFE')
        ->toContain('RUN --mount=type=secret,id=X.VALUE,env=X.VALUE')
        ->toContain('printenv X.VALUE');
});

it('rejects dotted Nixpacks variables when Docker build secrets are unavailable', function () {
    [$application, $server] = makeDeploymentControlVarFixture([
        'build_pack' => 'nixpacks',
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server, [
        'dockerBuildkitSupported' => true,
        'dockerSecretsAvailable' => false,
        'nixpacks_plan_json' => collect([
            'variables' => [
                'X.VALUE' => 'dotted-buildtime-ok',
                'ANOTHER.DOTTED.VALUE' => 'also-dotted',
            ],
        ]),
    ]);

    expect(fn () => invokeDeploymentJobMethod($job, $reflection, 'generate_build_env_variables'))
        ->toThrow(
            DeploymentException::class,
            'Dotted Nixpacks build-time environment variable names require Docker BuildKit secret support: X.VALUE, ANOTHER.DOTTED.VALUE. Rename these keys to use underscores instead of dots, or upgrade Docker on the build server.'
        );
});

it('writes dotted Nixpacks ARG and ENV removal when the Dockerfile has no run command', function () {
    [$application, $server] = makeDeploymentControlVarFixture([
        'build_pack' => 'nixpacks',
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server, [
        'env_args' => collect(['X.VALUE' => 'dotted-buildtime-ok']),
        'nixpacks_plan_json' => collect([
            'variables' => ['X.VALUE' => 'dotted-buildtime-ok'],
        ]),
        'build_secrets' => '--secret id=X.VALUE,env=X.VALUE',
        'saved_outputs' => collect([
            'dockerfile_content' => "FROM alpine\nARG X.VALUE=default\nENV X.VALUE=\$X.VALUE",
        ]),
    ]);

    invokeDeploymentJobMethod($job, $reflection, 'modify_dockerfile_for_secrets', '/artifacts/test-app/.nixpacks/Dockerfile');

    expect($job->writtenArtifacts['/artifacts/test-app/.nixpacks/Dockerfile'])
        ->not->toContain('X.VALUE');
});

it('skips unsafe reserved Nixpacks plan variable keys before validation', function (string $key) {
    [$application, $server] = makeDeploymentControlVarFixture([
        'build_pack' => 'nixpacks',
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server, [
        'nixpacks_plan_json' => collect([
            'variables' => [
                $key => 'value',
            ],
        ]),
    ]);

    /** @var Collection $buildtimeEnvs */
    $buildtimeEnvs = invokeDeploymentJobMethod($job, $reflection, 'generate_buildtime_environment_variables');

    expect($buildtimeEnvs->contains(fn (string $env) => str($env)->startsWith($key.'=')))->toBeFalse();
})->with([
    'Coolify key' => 'COOLIFY_$(id)',
    'service key' => 'SERVICE_$(id)',
]);

it('explains invalid Nixpacks plan variable keys in deployment logs', function () {
    [$application, $server] = makeDeploymentControlVarFixture([
        'build_pack' => 'nixpacks',
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server, [
        'nixpacks_plan_json' => collect([
            'variables' => [
                'XPACK;SECURITY;ENABLED' => 'true',
            ],
        ]),
    ]);

    expect(fn () => invokeDeploymentJobMethod($job, $reflection, 'generate_buildtime_environment_variables'))
        ->toThrow(DeploymentException::class, 'Invalid environment variable name from the Nixpacks plan: XPACK;SECURITY;ENABLED');

    $logs = implode("\n", $job->recordedLogEntries);

    expect($logs)
        ->toContain('Invalid environment variable name from the Nixpacks plan: XPACK;SECURITY;ENABLED')
        ->toContain('must start with a letter or underscore')
        ->toContain('How to fix')
        ->toContain('nixpacks.toml')
        ->toContain('https://nixpacks.com/docs/configuration/file');
});

it('truncates long Nixpacks plan variable keys in deployment logs', function () {
    [$application, $server] = makeDeploymentControlVarFixture([
        'build_pack' => 'nixpacks',
    ]);

    $key = 'X$(docker run --rm alpine sh -c "'.str_repeat('a', 200).'TAIL_SHOULD_BE_TRUNCATED")';

    [$job, $reflection] = makeControlVarFilteringJob($application, $server, [
        'nixpacks_plan_json' => collect([
            'variables' => [
                $key => 'x',
            ],
        ]),
    ]);

    expect(fn () => invokeDeploymentJobMethod($job, $reflection, 'generate_buildtime_environment_variables'))
        ->toThrow(DeploymentException::class);

    $logs = implode("\n", $job->recordedLogEntries);

    expect($logs)
        ->toContain('Invalid environment variable name from the Nixpacks plan: X$(docker run --rm')
        ->toContain('...')
        ->not->toContain('TAIL_SHOULD_BE_TRUNCATED')
        ->toContain('nixpacks.toml');
});

it('bounds every deployment log entry for long invalid Nixpacks variable keys', function () {
    [$application, $server] = makeDeploymentControlVarFixture([
        'build_pack' => 'nixpacks',
    ]);

    $key = 'X'.str_repeat('$', 10_000);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server, [
        'nixpacks_plan_json' => collect([
            'variables' => [
                $key => 'x',
            ],
        ]),
    ]);

    expect(fn () => invokeDeploymentJobMethod($job, $reflection, 'generate_buildtime_environment_variables'))
        ->toThrow(DeploymentException::class);

    $longestLogEntryLength = max(array_map(strlen(...), $job->recordedLogEntries));

    expect($longestLogEntryLength)->toBeLessThanOrEqual(200);
});

it('keeps shell-safe Nixpacks plan variables in the build-time env file', function () {
    [$application, $server] = makeDeploymentControlVarFixture([
        'build_pack' => 'nixpacks',
    ]);

    [$job, $reflection] = makeControlVarFilteringJob($application, $server, [
        'nixpacks_plan_json' => collect([
            'variables' => [
                'APP_NAME' => 'coolify',
                '_PRIVATE_VALUE' => 'secret',
                'X.VALUE' => 'dotted',
            ],
        ]),
    ]);

    /** @var Collection $buildtimeEnvs */
    $buildtimeEnvs = invokeDeploymentJobMethod($job, $reflection, 'generate_buildtime_environment_variables');

    expect($buildtimeEnvs)->toContain("APP_NAME='coolify'")
        ->toContain("_PRIVATE_VALUE='secret'")
        ->toContain("X.VALUE='dotted'");
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
