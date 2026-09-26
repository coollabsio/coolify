<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const SENSITIVE_FAILURE_SECRET = 'harmless-secret-marker-7431';

class SensitiveCommandFailureDeploymentJob extends ApplicationDeploymentJob
{
    public function __construct() {}
}

beforeEach(function () {
    config()->set('constants.ssh.mux_enabled', false);
    config()->set('constants.ssh.max_retries', 1);
});

/**
 * @return array{0: SensitiveCommandFailureDeploymentJob, 1: ReflectionClass, 2: ApplicationDeploymentQueue, 3: Application}
 */
function makeSensitiveCommandFailureJob(array $environmentVariables = []): array
{
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $privateKeyContent = "-----BEGIN OPENSSH PRIVATE KEY-----\n"
        ."b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW\n"
        ."QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk\n"
        ."hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA\n"
        ."AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV\n"
        ."uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==\n"
        .'-----END OPENSSH PRIVATE KEY-----';

    $privateKey = PrivateKey::create([
        'name' => 'sensitive-failure-key-'.uniqid(),
        'private_key' => $privateKeyContent,
        'team_id' => $team->id,
    ]);

    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKeyContent);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'user' => 'root',
    ]);

    $project = Project::create(['name' => 'Sensitive Failure Project', 'team_id' => $team->id]);
    $environment = Environment::where('project_id', $project->id)->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'build_pack' => 'dockerfile',
    ]);
    $application->settings()->update([
        'include_source_commit_in_build' => false,
        'is_env_sorting_enabled' => false,
    ]);

    foreach ($environmentVariables as $attributes) {
        EnvironmentVariable::create([
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

    $queue = ApplicationDeploymentQueue::create([
        'deployment_uuid' => 'sensitive-failure-deployment',
        'application_id' => $application->id,
        'server_id' => $server->id,
        'status' => 'in_progress',
    ]);

    $job = new SensitiveCommandFailureDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    foreach ([
        'application' => $application->fresh(),
        'application_deployment_queue' => $queue,
        'server' => $server,
        'mainServer' => $server,
        'build_pack' => 'dockerfile',
        'pull_request_id' => 0,
        'commit' => 'HEAD',
        'basedir' => '/artifacts/sensitive-failure',
        'workdir' => '/artifacts/sensitive-failure',
        'configuration_dir' => '/data/coolify/applications/sensitive-failure',
        'deployment_uuid' => 'sensitive-failure-deployment',
        'dockerfile_location' => '/Dockerfile',
        'container_name' => 'sensitive-failure-app',
        'coolify_variables' => null,
        'dockerSecretsSupported' => false,
        'saved_outputs' => new Collection,
    ] as $property => $value) {
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($job, $value);
    }

    return [$job, $reflection, $queue, $application];
}

function invokeSensitiveFailureJobMethod(object $job, ReflectionClass $reflection, string $method, mixed ...$arguments): mixed
{
    $reflectionMethod = $reflection->getMethod($method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invoke($job, ...$arguments);
}

function captureDeploymentException(callable $callback): DeploymentException
{
    try {
        $callback();
    } catch (DeploymentException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a DeploymentException.');
}

/**
 * @return list<string>
 */
function base64PayloadsInCommand(string $command): array
{
    preg_match_all('~[A-Za-z0-9+/]{16,}={0,2}~', $command, $matches);

    return array_values(array_filter(
        $matches[0],
        static fn (string $candidate): bool => str_contains((string) base64_decode($candidate, true), SENSITIVE_FAILURE_SECRET)
    ));
}

it('hides the .env write command and its base64 payload when the write fails', function () {
    [$job, $reflection, $queue] = makeSensitiveCommandFailureJob([
        ['key' => 'APP_SECRET', 'value' => SENSITIVE_FAILURE_SECRET],
    ]);

    $payloads = [];
    Process::fake(function (PendingProcess $process) use (&$payloads) {
        $payloads = array_merge($payloads, base64PayloadsInCommand($process->command));

        return Process::result(errorOutput: 'tee: /artifacts/sensitive-failure/.env: No space left on device', exitCode: 1);
    });

    $exception = captureDeploymentException(fn () => invokeSensitiveFailureJobMethod($job, $reflection, 'save_runtime_environment_variables'));

    expect($payloads)->not->toBeEmpty();
    expect($exception->getMessage())
        ->toContain('Command execution failed (exit code 1): [command hidden because it contains sensitive data]')
        ->toContain('No space left on device')
        ->not->toContain(SENSITIVE_FAILURE_SECRET)
        ->not->toContain('base64 -d');
    foreach ($payloads as $payload) {
        expect($exception->getMessage())->not->toContain($payload);
    }

    $storedLogs = (string) $queue->fresh()->logs;
    expect($storedLogs)
        ->toContain('No space left on device')
        ->not->toContain(SENSITIVE_FAILURE_SECRET);
    foreach ($payloads as $payload) {
        expect($storedLogs)->not->toContain($payload);
    }
});

it('redacts the sensitive payload when the error output echoes the command', function () {
    [$job, $reflection, $queue] = makeSensitiveCommandFailureJob([
        ['key' => 'APP_SECRET', 'value' => SENSITIVE_FAILURE_SECRET],
    ]);

    $payloads = [];
    Process::fake(function (PendingProcess $process) use (&$payloads) {
        $commandPayloads = base64PayloadsInCommand($process->command);
        $payloads = array_merge($payloads, $commandPayloads);

        return Process::result(errorOutput: "bash: line 1: echo '".($commandPayloads[0] ?? '')."': unexpected failure", exitCode: 2);
    });

    $exception = captureDeploymentException(fn () => invokeSensitiveFailureJobMethod($job, $reflection, 'save_runtime_environment_variables'));

    expect($payloads)->not->toBeEmpty();
    $storedLogs = (string) $queue->fresh()->logs;
    foreach ($payloads as $payload) {
        expect($exception->getMessage())->not->toContain($payload);
        expect($storedLogs)->not->toContain($payload);
    }
    expect($exception->getMessage())->toContain('unexpected failure');
});

it('hides the build-time env write command when the write fails', function () {
    [$job, $reflection] = makeSensitiveCommandFailureJob([
        ['key' => 'BUILD_SECRET', 'value' => SENSITIVE_FAILURE_SECRET, 'is_runtime' => false],
    ]);

    $payloads = [];
    Process::fake(function (PendingProcess $process) use (&$payloads) {
        $payloads = array_merge($payloads, base64PayloadsInCommand($process->command));

        return Process::result(errorOutput: 'tee: /artifacts/build-time.env: No such file or directory', exitCode: 1);
    });

    $exception = captureDeploymentException(fn () => invokeSensitiveFailureJobMethod($job, $reflection, 'save_buildtime_environment_variables'));

    expect($payloads)->not->toBeEmpty();
    expect($exception->getMessage())
        ->toContain('[command hidden because it contains sensitive data]')
        ->toContain('No such file or directory')
        ->not->toContain(SENSITIVE_FAILURE_SECRET);
    foreach ($payloads as $payload) {
        expect($exception->getMessage())->not->toContain($payload);
    }
});

it('keeps the command in the error message for failed commands that are not sensitive', function () {
    [$job] = makeSensitiveCommandFailureJob();

    Process::fake(['*' => Process::result(errorOutput: 'ls: cannot access: No such file or directory', exitCode: 2)]);

    $exception = captureDeploymentException(fn () => $job->execute_remote_command([
        'command' => 'ls /artifacts/harmless-missing-directory',
        'hidden' => true,
    ]));

    expect($exception->getMessage())
        ->toContain('Command execution failed (exit code 2): ls /artifacts/harmless-missing-directory')
        ->toContain('No such file or directory')
        ->not->toContain('[command hidden because it contains sensitive data]');
});

it('redacts locked values from the error output of failed commands', function () {
    [$job] = makeSensitiveCommandFailureJob([
        ['key' => 'LOCKED_SECRET', 'value' => SENSITIVE_FAILURE_SECRET, 'is_shown_once' => true],
    ]);

    Process::fake(['*' => Process::result(errorOutput: 'failed with value '.SENSITIVE_FAILURE_SECRET, exitCode: 1)]);

    $exception = captureDeploymentException(fn () => $job->execute_remote_command([
        'command' => 'harmless-command --flag',
    ]));

    expect($exception->getMessage())
        ->toContain('harmless-command --flag')
        ->toContain('failed with value '.REDACTED)
        ->not->toContain(SENSITIVE_FAILURE_SECRET);
});

it('logs only the variable names of the .env files in development mode', function () {
    config()->set('app.env', 'local');
    [$job, $reflection, $queue] = makeSensitiveCommandFailureJob([
        ['key' => 'APP_SECRET', 'value' => SENSITIVE_FAILURE_SECRET],
    ]);

    $ranCommands = [];
    Process::fake(function (PendingProcess $process) use (&$ranCommands) {
        $ranCommands[] = $process->command;

        return Process::result(output: str_contains($process->command, 'cat ') ? 'APP_SECRET='.SENSITIVE_FAILURE_SECRET : '');
    });

    invokeSensitiveFailureJobMethod($job, $reflection, 'save_runtime_environment_variables');
    invokeSensitiveFailureJobMethod($job, $reflection, 'save_buildtime_environment_variables');

    $logEntries = collect(json_decode((string) $queue->fresh()->logs, true));
    $keyListEntries = $logEntries->filter(fn (array $entry): bool => str_contains((string) $entry['output'], '[DEBUG] Variable names in'));

    expect($keyListEntries)->toHaveCount(2)
        ->each(fn ($entry) => $entry->hidden->toBeTrue()->output->toContain('APP_SECRET'));
    expect($logEntries->pluck('output')->merge($logEntries->pluck('command'))->filter()->implode("\n"))
        ->not->toContain('APP_SECRET='.SENSITIVE_FAILURE_SECRET)
        ->not->toContain('APP_SECRET="'.SENSITIVE_FAILURE_SECRET);
    expect(collect($ranCommands)->filter(fn (string $command): bool => str_contains($command, 'cat /artifacts/sensitive-failure/.env') || str_contains($command, 'cat '.ApplicationDeploymentJob::BUILD_TIME_ENV_PATH)))
        ->toBeEmpty();
});

function setSensitiveFailureJobProperties(object $job, ReflectionClass $reflection, array $properties): void
{
    foreach ($properties as $property => $value) {
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($job, $value);
    }
}

it('hides the helper container command when build secrets are passed as environment flags', function () {
    [$job, $reflection, $queue, $application] = makeSensitiveCommandFailureJob([
        ['key' => 'BUILD_SECRET', 'value' => SENSITIVE_FAILURE_SECRET, 'is_runtime' => false],
    ]);
    $application->settings()->update(['use_build_secrets' => true]);
    $server = readSensitiveFailureJobProperty($job, $reflection, 'server');
    setSensitiveFailureJobProperties($job, $reflection, [
        'application' => $application->fresh(),
        'destination' => StandaloneDocker::forceCreate([
            'name' => 'sensitive-failure-network',
            'network' => 'sensitive-failure-network',
            'server_id' => $server->id,
        ]),
    ]);

    Process::fake(function (PendingProcess $process) {
        if (str_contains($process->command, 'docker run -d')) {
            return Process::result(errorOutput: 'docker: Error response from daemon: network not found.', exitCode: 125);
        }

        return Process::result(output: str_contains($process->command, 'echo $HOME') ? '/root' : 'NOK');
    });

    $exception = captureDeploymentException(fn () => invokeSensitiveFailureJobMethod($job, $reflection, 'prepare_builder_image'));

    expect($exception->getMessage())
        ->toContain('[command hidden because it contains sensitive data]')
        ->toContain('network not found')
        ->not->toContain(SENSITIVE_FAILURE_SECRET);
    expect((string) $queue->fresh()->logs)->not->toContain(SENSITIVE_FAILURE_SECRET);
});

it('hides the Railpack prepare command that passes build-time values', function () {
    [$job, $reflection, $queue, $application] = makeSensitiveCommandFailureJob([
        ['key' => 'BUILD_SECRET', 'value' => SENSITIVE_FAILURE_SECRET, 'is_runtime' => false],
    ]);
    $application->update(['build_pack' => 'railpack']);
    setSensitiveFailureJobProperties($job, $reflection, [
        'application' => $application->fresh(),
        'build_pack' => 'railpack',
        'dockerBuildxAvailable' => true,
    ]);

    Process::fake(function (PendingProcess $process) {
        if (str_contains($process->command, 'railpack prepare')) {
            return Process::result(errorOutput: 'railpack: failed to detect a provider', exitCode: 1);
        }

        return Process::result(output: str_contains($process->command, 'buildx version') ? 'available' : 'missing');
    });

    $exception = captureDeploymentException(fn () => invokeSensitiveFailureJobMethod($job, $reflection, 'build_railpack_image'));

    expect($exception->getMessage())
        ->toContain('[command hidden because it contains sensitive data]')
        ->toContain('failed to detect a provider')
        ->not->toContain(SENSITIVE_FAILURE_SECRET);
    expect((string) $queue->fresh()->logs)->not->toContain(SENSITIVE_FAILURE_SECRET);
});

it('hides the Nixpacks plan write command that contains build-time values', function (bool $isStatic) {
    [$job, $reflection, $queue, $application] = makeSensitiveCommandFailureJob();
    $application->update(['build_pack' => 'nixpacks']);
    $application->settings()->update(['is_static' => $isStatic]);
    setSensitiveFailureJobProperties($job, $reflection, [
        'application' => $application->fresh(),
        'build_pack' => 'nixpacks',
        'build_args' => collect(),
        'disableBuildCache' => false,
        'force_rebuild' => false,
        'nixpacks_plan' => json_encode(['variables' => ['BUILD_SECRET' => SENSITIVE_FAILURE_SECRET]]),
    ]);

    $payloads = [];
    Process::fake(function (PendingProcess $process) use (&$payloads) {
        $commandPayloads = base64PayloadsInCommand($process->command);
        if ($commandPayloads !== []) {
            $payloads = array_merge($payloads, $commandPayloads);

            return Process::result(errorOutput: 'tee: /artifacts/thegameplan.json: No space left on device', exitCode: 1);
        }

        return Process::result();
    });

    $exception = captureDeploymentException(fn () => invokeSensitiveFailureJobMethod($job, $reflection, 'build_image'));

    expect($payloads)->not->toBeEmpty();
    expect($exception->getMessage())
        ->toContain('[command hidden because it contains sensitive data]')
        ->not->toContain(SENSITIVE_FAILURE_SECRET);
    $storedLogs = (string) $queue->fresh()->logs;
    foreach ($payloads as $payload) {
        expect($exception->getMessage())->not->toContain($payload);
        expect($storedLogs)->not->toContain($payload);
    }
})->with([
    'static' => [true],
    'not static' => [false],
]);

it('hides the compose file write command because compose files can contain secrets', function () {
    [$job, $reflection, $queue, $application] = makeSensitiveCommandFailureJob();
    $server = readSensitiveFailureJobProperty($job, $reflection, 'server');
    setSensitiveFailureJobProperties($job, $reflection, [
        'destination' => $server->standaloneDockers()->firstOrFail(),
        'production_image_name' => 'example/app:latest',
    ]);

    $payloads = [];
    Process::fake(function (PendingProcess $process) use (&$payloads) {
        preg_match_all('~[A-Za-z0-9+/]{16,}={0,2}~', $process->command, $matches);
        $composePayloads = array_filter($matches[0], fn (string $candidate): bool => str_contains((string) base64_decode($candidate, true), 'services:'));
        if ($composePayloads !== []) {
            $payloads = array_merge($payloads, $composePayloads);

            return Process::result(errorOutput: 'tee: docker-compose.yaml: No space left on device', exitCode: 1);
        }

        return Process::result();
    });

    $exception = captureDeploymentException(fn () => invokeSensitiveFailureJobMethod($job, $reflection, 'generate_compose_file'));

    expect($payloads)->not->toBeEmpty();
    expect($exception->getMessage())
        ->toContain('[command hidden because it contains sensitive data]')
        ->toContain('No space left on device');
    $storedLogs = (string) $queue->fresh()->logs;
    foreach ($payloads as $payload) {
        expect($exception->getMessage())->not->toContain($payload);
        expect($storedLogs)->not->toContain($payload);
    }
});

function readSensitiveFailureJobProperty(object $job, ReflectionClass $reflection, string $property): mixed
{
    $reflectionProperty = $reflection->getProperty($property);
    $reflectionProperty->setAccessible(true);

    return $reflectionProperty->getValue($job);
}
