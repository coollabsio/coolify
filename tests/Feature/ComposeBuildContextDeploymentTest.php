<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

/**
 * Runs deployment commands on this machine. A fake `docker` turns `docker exec <id> bash -c ...`
 * into a local `bash -c ...`, so the real ARG injection commands run against a temporary repository.
 */
class LocallyExecutedComposeDeploymentJob extends ApplicationDeploymentJob
{
    public string $binaries;

    public array $logEntries = [];

    public function __construct() {}

    public function execute_remote_command(...$commands)
    {
        $savedOutputs = (new ReflectionProperty(ApplicationDeploymentJob::class, 'saved_outputs'))->getValue($this);
        foreach ($commands as $command) {
            $process = new Process(['/bin/bash', '-c', $command[0]], env: ['PATH' => $this->binaries.':'.getenv('PATH')]);
            $process->run();
            if (isset($command['save'])) {
                $savedOutputs->put($command['save'], trim($process->getOutput()));
            }
        }
    }
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->root = sys_get_temp_dir().'/coolify-compose-context-'.bin2hex(random_bytes(4));
    $this->basedir = $this->root.'/artifacts/deployment';
    mkdir($this->basedir.'/apps/web', 0755, true);
    mkdir($this->root.'/bin');
    file_put_contents($this->root.'/bin/docker', "#!/bin/sh\nif [ \"\$1\" = exec ]; then shift 2; exec \"\$@\"; fi\nexit 1\n");
    chmod($this->root.'/bin/docker', 0755);
});

afterEach(function () {
    (new Process(['rm', '-rf', $this->root]))->run();
});

function composeDockerfile(string $path): string
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, "FROM alpine\nRUN echo build\n");

    return $path;
}

function runComposeArgInjection(object $test, mixed $build, string $workdir): LocallyExecutedComposeDeploymentJob
{
    $application = Application::factory()->create(['build_pack' => 'dockercompose']);
    $application->settings()->update(['inject_build_args_to_dockerfile' => true]);

    $job = new LocallyExecutedComposeDeploymentJob;
    $job->binaries = $test->root.'/bin';
    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function (string $message) use ($job) {
        $job->logEntries[] = $message;
    });

    foreach ([
        'application' => $application->fresh(),
        'application_deployment_queue' => $queue,
        'deployment_uuid' => 'deployment-uuid',
        'basedir' => $test->basedir,
        'workdir' => $workdir,
        'env_args' => collect(['APP_ENV' => 'production']),
        'saved_outputs' => collect(),
        'dockerSecretsSupported' => false,
        'pull_request_id' => 0,
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    (new ReflectionMethod(ApplicationDeploymentJob::class, 'modify_dockerfiles_for_compose'))
        ->invoke($job, ['services' => ['api' => ['build' => $build]]]);

    return $job;
}

test('compose deployments inject build args for Dockerfiles inside the repository', function (mixed $build, string $workdir, string $dockerfile) {
    $dockerfile = composeDockerfile($this->basedir.'/'.$dockerfile);
    // The build context folder always exists in a real repository.
    @mkdir(rtrim($this->basedir.'/'.$workdir, '/').'/'.(is_array($build) ? $build['context'] : $build), 0755, true);

    $job = runComposeArgInjection($this, $build, rtrim($this->basedir.'/'.$workdir, '/'));

    expect(file_get_contents($dockerfile))->toContain("FROM alpine\nARG APP_ENV")
        ->and($job->logEntries)->toContain('Added 1 ARG declarations to Dockerfile for service api.');
})->with([
    'context in the project directory' => ['.', '', 'Dockerfile'],
    'monorepo context above the base directory' => [['context' => '../..'], 'apps/web', 'Dockerfile'],
    'path with a space' => ['my app', '', 'my app/Dockerfile'],
    'custom Dockerfile in a parent folder' => [['context' => 'services/api', 'dockerfile' => '../docker/api.Dockerfile'], '', 'services/docker/api.Dockerfile'],
]);

test('compose deployments skip build contexts they cannot inspect locally', function (mixed $build) {
    $dockerfile = composeDockerfile($this->basedir.'/Dockerfile');

    $job = runComposeArgInjection($this, $build, $this->basedir);

    expect(file_get_contents($dockerfile))->not->toContain('ARG APP_ENV')
        ->and($job->logEntries)->toContain('The build context of service api is remote or uses variables, skipping ARG injection.');
})->with([
    'git URL' => ['https://github.com/coollabsio/coolify.git#main:docker'],
    'context variable' => ['${APP_DIR:-.}'],
    'inline Dockerfile' => [['context' => '.', 'dockerfile_inline' => "FROM alpine\n"]],
]);

test('compose deployments never change Dockerfiles outside the repository', function (string $escape) {
    $outside = composeDockerfile($this->root.'/outside/Dockerfile');
    symlink($this->root.'/outside', $this->basedir.'/linked');
    $build = match ($escape) {
        'parent folder' => ['context' => '../../outside'],
        'symlink' => 'linked',
        'absolute path' => ['context' => $this->root.'/outside'],
    };

    $job = runComposeArgInjection($this, $build, $this->basedir);

    expect(file_get_contents($outside))->not->toContain('ARG APP_ENV')
        ->and(collect($job->logEntries)->contains(fn (string $entry) => str_starts_with($entry, 'Dockerfile not found for service api')))->toBeTrue();
})->with(['parent folder', 'symlink', 'absolute path']);

test('compose build paths never run as shell commands', function (string $field, string $template) {
    composeDockerfile($this->basedir.'/Dockerfile');
    $marker = $this->root.'/pwned';
    $value = str_replace('MARKER', $marker, $template);
    $build = $field === 'context' ? ['context' => $value] : ['context' => '.', 'dockerfile' => $value];

    runComposeArgInjection($this, $build, $this->basedir);

    expect(file_exists($marker))->toBeFalse();
})->with([
    'context semicolon' => ['context', '.; touch MARKER'],
    'context command substitution' => ['context', '$(touch MARKER)'],
    'context backticks' => ['context', '`touch MARKER`'],
    'context newline' => ['context', ".\ntouch MARKER"],
    'dockerfile semicolon' => ['dockerfile', 'Dockerfile; touch MARKER'],
    'dockerfile quote' => ['dockerfile', "Dockerfile'; touch MARKER; '"],
    'dockerfile newline' => ['dockerfile', "Dockerfile\ntouch MARKER"],
]);

test('compose deployments do not reject build paths before the build', function () {
    // Compose resolves build contexts itself; only the ARG injection step inspects them, safely.
    expect(method_exists(ApplicationDeploymentJob::class, 'validateComposeBuildPaths'))->toBeFalse();
});
