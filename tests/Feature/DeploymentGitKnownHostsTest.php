<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Application\Deployment\Show;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function knownHostsTestApplication(string $knownHosts = 'seed.example ssh-ed25519 AAAA'): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();

    return Application::withoutEvents(fn () => Application::forceCreate(['environment_id' => $environment->id, 'uuid' => 'host-keys-test', 'git_repository' => 'git@example.com:repo', 'git_branch' => 'main', 'build_pack' => 'dockerfile', 'name' => 'host keys', 'git_ssh_known_hosts' => $knownHosts]));
}

function knownHostsTestQueue(Application $application, string $status = 'in_progress'): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::forceCreate(['application_id' => $application->id, 'deployment_uuid' => 'test', 'status' => $status, 'logs' => '[]']);
}

/**
 * A job for check_git_if_build_needed(): the application returns no git commands, and $run handles each remote command.
 */
function knownHostsTestCheckGitJob(Application $stored, ApplicationDeploymentQueue $queue, Closure $run): ApplicationDeploymentJob
{
    $job = new class($run) extends ApplicationDeploymentJob
    {
        public function __construct(public Closure $run) {}

        public function execute_remote_command(...$commands)
        {
            ($this->run)($commands, (new ReflectionProperty(ApplicationDeploymentJob::class, 'saved_outputs'))->getValue($this));
        }
    };
    $application = new class extends Application
    {
        public function generateGitImportCommands(string $deployment_uuid, int $pull_request_id = 0, ?string $git_type = null, bool $exec_in_docker = true, bool $only_checkout = false, ?string $custom_base_dir = null, ?string $commit = null)
        {
            return ['commands' => collect(), 'branch' => 'main', 'fullRepoUrl' => 'git@example.com:repo'];
        }
    };
    $application->setRawAttributes($stored->getAttributes(), true);
    $application->exists = true;
    $application->setRelation('settings', (object) ['include_source_commit_in_build' => false, 'use_build_secrets' => false]);
    $application->setRelation('private_key', null);
    foreach (['application' => $application, 'application_deployment_queue' => $queue, 'deployment_uuid' => 'test', 'pull_request_id' => 0, 'commit' => 'HEAD', 'rollback' => false, 'git_type' => 'other', 'branch' => 'main', 'saved_outputs' => collect()] as $name => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $name))->setValue($job, $value);
    }

    return $job;
}

it('merges accepted keys with current storage without restoring removed seeds', function (string $current, string $expected, ?string $expectedLog) {
    $seed = 'seed.example ssh-ed25519 AAAA';
    $accepted = 'new.example ssh-ed25519 BBBB';
    $application = knownHostsTestApplication($seed);
    Application::query()->whereKey($application->id)->update(['git_ssh_known_hosts' => $current]);
    $logs = [];
    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('addLogEntry')->zeroOrMoreTimes()->andReturnUsing(function (string $message, string $type = 'stdout') use (&$logs) {
        $logs[] = [$type, $message];
    });
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $outputs = collect();
    foreach (['application' => $application, 'application_deployment_queue' => $queue, 'deployment_uuid' => 'test', 'saved_outputs' => $outputs] as $name => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $name))->setValue($job, $value);
    }
    $job->shouldReceive('execute_remote_command')->once()->andReturnUsing(function () use ($outputs, $seed, $accepted) {
        $outputs->put('git_ssh_known_hosts', "$seed\n$accepted");
    });

    (new ReflectionMethod(ApplicationDeploymentJob::class, 'save_git_ssh_known_hosts'))->invoke($job);

    expect($application->fresh()->git_ssh_known_hosts)->toBe($expected);
    expect($application->isDirty('git_ssh_known_hosts'))->toBeFalse();
    expect(collect($logs)->where(0, 'stderr')->pluck(1)->all())->toBe($expectedLog === null ? [] : [$expectedLog]);
})->with([
    'concurrent addition' => ['other.example ssh-ed25519 CCCC', "other.example ssh-ed25519 CCCC\nnew.example ssh-ed25519 BBBB", null],
    'already accepted' => ['new.example ssh-ed25519 BBBB', 'new.example ssh-ed25519 BBBB', null],
    'concurrent different key of the same type' => ['new.example ssh-ed25519 CCCC', 'new.example ssh-ed25519 CCCC', 'Coolify did not save the SSH host key of new.example (ssh-ed25519). Another deployment saved a different key of this type at the same time. Later deployments accept only the saved key.'],
    'concurrent key of another type' => ['new.example ecdsa-sha2-nistp256 DDDD', "new.example ecdsa-sha2-nistp256 DDDD\nnew.example ssh-ed25519 BBBB", null],
]);

it('saves the host keys that ls-remote accepted, also when the build is skipped later', function () {
    $application = knownHostsTestApplication();
    $queue = knownHostsTestQueue($application);
    $job = knownHostsTestCheckGitJob($application, $queue, function (array $commands, $outputs) {
        if (str_contains($commands[0]['command'] ?? $commands[0][0], 'cat /root/.ssh/coolify_git_test.known_hosts')) {
            $outputs->put('git_ssh_known_hosts', "seed.example ssh-ed25519 AAAA\nnew.example ssh-ed25519 BBBB");
        }
    });

    (new ReflectionMethod(ApplicationDeploymentJob::class, 'check_git_if_build_needed'))->invoke($job);

    expect($application->fresh()->git_ssh_known_hosts)->toBe("seed.example ssh-ed25519 AAAA\nnew.example ssh-ed25519 BBBB");
});

it('logs the host key hint when a git command of Coolify fails with a host key error', function () {
    $application = knownHostsTestApplication();
    $queue = knownHostsTestQueue($application);
    $job = knownHostsTestCheckGitJob($application, $queue, function () {
        throw new DeploymentException("Command execution failed (exit code 128): git ls-remote\nError: Host key verification failed.\nfatal: Could not read from remote repository.", 128);
    });

    expect(fn () => (new ReflectionMethod(ApplicationDeploymentJob::class, 'check_git_if_build_needed'))->invoke($job))->toThrow(DeploymentException::class);

    expect($queue->fresh()->logs)->toContain(ApplicationDeploymentJob::GIT_HOST_KEY_CHANGED_HINT);
});

it('shows the host key callout only when the git step of Coolify failed with a host key error', function (?string $log, string $status, bool $shown) {
    $application = knownHostsTestApplication();
    $queue = knownHostsTestQueue($application, $status);
    $queue->addLogEntry($log ?? ApplicationDeploymentJob::GIT_HOST_KEY_CHANGED_HINT, 'stderr');
    $component = new Show;
    $component->application = $application;
    $component->application_deployment_queue = $queue->fresh();

    expect($component->getHostKeyChangedSourceUrlProperty() !== null)->toBe($shown);
})->with([
    'hint from the git step' => [null, 'failed', true],
    'ssh error from a Dockerfile RUN step' => ['Deployment failed: RUN git clone git@example.com:repo: Host key verification failed.', 'failed', false],
    'deployment still running' => [null, 'in_progress', false],
]);
