<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Simulates the helper container: the deployed commit exists only in the repository root, as when the
 * base directory is a git submodule. Running `git log` anywhere else fails like git does (exit 128).
 */
class CommitMessageDeploymentJob extends ApplicationDeploymentJob
{
    public bool $commitExists = true;

    public function __construct() {}

    public function execute_remote_command(...$commands): void
    {
        foreach ($commands as $command) {
            $commandText = $command[0] ?? $command['command'];
            if (! str_contains($commandText, 'git log')) {
                continue;
            }
            if (! $this->commitExists || ! str_contains($commandText, 'cd /artifacts/deploy-uuid && git log')) {
                if (! data_get($command, 'ignore_errors', false)) {
                    throw new DeploymentException('fatal: bad object abc123', 128);
                }

                continue;
            }
            (new ReflectionProperty(ApplicationDeploymentJob::class, 'saved_outputs'))->getValue($this)->put($command['save'], "Parent repository commit\n");
        }
    }
}

beforeEach(function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('generateGitImportCommands')->andReturn([
        'commands' => "docker exec deploy-uuid bash -c 'git clone repo /artifacts/deploy-uuid'",
        'branch' => 'main',
        'fullRepoUrl' => 'repo',
    ]);
    $application->id = 1;
    $application->setRelation('settings', null);

    $this->queue = $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('addLogEntry');

    $this->job = new CommitMessageDeploymentJob;
    foreach ([
        'application' => $application,
        'application_deployment_queue' => $queue,
        'deployment_uuid' => 'deploy-uuid',
        'basedir' => '/artifacts/deploy-uuid',
        'workdir' => '/artifacts/deploy-uuid/frontend',
        'pull_request_id' => 0,
        'commit' => 'abc123',
        'saved_outputs' => collect(),
        'use_build_server' => false,
        'configuration_dir' => '/data/coolify/applications/app-uuid',
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($this->job, $value);
    }
});

function cloneRepository(CommitMessageDeploymentJob $job): void
{
    (new ReflectionMethod(ApplicationDeploymentJob::class, 'clone_repository'))->invoke($job);
}

it('reads the commit message from the repository root when the base directory is a submodule', function () {
    cloneRepository($this->job);

    expect($this->queue->commit_message)->toBe('Parent repository commit');
});

it('continues the deployment when the commit message cannot be read', function () {
    $this->job->commitExists = false;

    cloneRepository($this->job);

    expect($this->queue->commit_message)->toBeNull();
});
