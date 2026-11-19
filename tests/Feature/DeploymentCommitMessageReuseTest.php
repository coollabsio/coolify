<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

function createCommitMessageDeployment(Application $application, Server $server, string $commit, ?string $commitMessage = null): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => new_public_id(),
        'server_id' => $server->id,
        'commit' => $commit,
        'commit_message' => $commitMessage,
    ]);
}

function reuseCommitMessage(ApplicationDeploymentQueue $deployment, Application $application, string $commit): void
{
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('application')->setValue($job, $application);
    $reflection->getProperty('application_deployment_queue')->setValue($job, $deployment);
    $reflection->getProperty('commit')->setValue($job, $commit);

    $reflection->getMethod('reuse_commit_message')->invoke($job);
}

test('copies the commit message from an earlier deployment of the same commit', function () {
    $commit = str_repeat('a', 40);
    createCommitMessageDeployment($this->application, $this->server, $commit, 'feat: add portfolio work page');
    $deployment = createCommitMessageDeployment($this->application, $this->server, $commit);

    reuseCommitMessage($deployment, $this->application, $commit);

    expect($deployment->fresh()->commit_message)->toBe('feat: add portfolio work page');
});

test('does not copy a commit message from another commit or application', function () {
    $commit = str_repeat('a', 40);
    createCommitMessageDeployment($this->application, $this->server, str_repeat('b', 40), 'other commit');
    $otherApplication = Application::factory()->create([
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);
    createCommitMessageDeployment($otherApplication, $this->server, $commit, 'other application');
    $deployment = createCommitMessageDeployment($this->application, $this->server, $commit);

    reuseCommitMessage($deployment, $this->application, $commit);

    expect($deployment->fresh()->commit_message)->toBeNull();
});

test('does not look up a commit message for an unresolved HEAD commit', function () {
    createCommitMessageDeployment($this->application, $this->server, 'HEAD', 'stale message');
    $deployment = createCommitMessageDeployment($this->application, $this->server, 'HEAD');

    reuseCommitMessage($deployment, $this->application, 'HEAD');

    expect($deployment->fresh()->commit_message)->toBeNull();
});
