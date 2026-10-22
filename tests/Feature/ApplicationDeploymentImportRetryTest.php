<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Sleep;

/**
 * Records remote commands and fails the repository import with the queued exit codes.
 */
class ImportRetryDeploymentJob extends ApplicationDeploymentJob
{
    public array $commands = [];

    public array $importExitCodes = [];

    public function __construct() {}

    public function execute_remote_command(...$commands): void
    {
        foreach ($commands as $command) {
            $this->commands[] = $command[0];
            if (str_contains($command[0], 'git clone') && $exitCode = array_shift($this->importExitCodes)) {
                throw new DeploymentException('Command execution failed', $exitCode);
            }
        }
    }
}

beforeEach(function () {
    Sleep::fake();
    config()->set('constants.ssh.max_retries', 3);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('generateGitImportCommands')->andReturn([
        'commands' => "docker exec deploy-uuid bash -c 'git clone repo /artifacts/deploy-uuid'",
        'branch' => 'main',
        'fullRepoUrl' => 'repo',
    ]);

    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('addLogEntry');

    $this->job = new ImportRetryDeploymentJob;
    foreach ([
        'application' => $application,
        'application_deployment_queue' => $queue,
        'deployment_uuid' => 'deploy-uuid',
        'basedir' => '/artifacts/deploy-uuid',
        'pull_request_id' => 0,
        'commit' => 'HEAD',
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($this->job, $value);
    }
});

function importRepository(ImportRetryDeploymentJob $job): void
{
    (new ReflectionMethod(ApplicationDeploymentJob::class, 'import_repository'))->invoke($job);
}

function cloneCommandCount(ImportRetryDeploymentJob $job): int
{
    return collect($job->commands)->filter(fn (string $command) => str_contains($command, 'git clone'))->count();
}

it('cleans up and clones again when the SSH connection drops during the import', function () {
    $this->job->importExitCodes = [255];

    importRepository($this->job);

    expect(cloneCommandCount($this->job))->toBe(2)
        ->and($this->job->commands[1])->toBe(executeInDocker('deploy-uuid', "pkill git; rm -rf '/artifacts/deploy-uuid'"));
});

it('does not retry the import when git itself fails', function () {
    $this->job->importExitCodes = [128];

    expect(fn () => importRepository($this->job))->toThrow(DeploymentException::class);
    expect(cloneCommandCount($this->job))->toBe(1);
});

it('stops retrying the import after the configured SSH attempts', function () {
    $this->job->importExitCodes = [255, 255, 255];

    expect(fn () => importRepository($this->job))->toThrow(DeploymentException::class);
    expect(cloneCommandCount($this->job))->toBe(3);
});
