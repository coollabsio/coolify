<?php

use App\Exceptions\DeploymentException;
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

function createPreviewDeployment(Application $application, Server $server, int $pullRequestId, string $commit): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => new_public_id(),
        'server_id' => $server->id,
        'pull_request_id' => $pullRequestId,
        'git_type' => 'bitbucket',
        'commit' => $commit,
    ]);
}

function resolveBitbucketPullRequestCommit(ApplicationDeploymentQueue $deployment, Application $application, string $gitType = 'bitbucket'): ApplicationDeploymentJob
{
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('application')->setValue($job, $application);
    $reflection->getProperty('application_deployment_queue')->setValue($job, $deployment);
    $reflection->getProperty('pull_request_id')->setValue($job, $deployment->pull_request_id);
    $reflection->getProperty('git_type')->setValue($job, $gitType);
    $reflection->getProperty('commit')->setValue($job, $deployment->commit);

    $reflection->getMethod('resolve_bitbucket_pull_request_commit')->invoke($job);

    return $job;
}

function deploymentJobCommit(ApplicationDeploymentJob $job): string
{
    return (new ReflectionProperty(ApplicationDeploymentJob::class, 'commit'))->getValue($job);
}

test('a manual bitbucket preview redeploy builds the commit of the latest deployment of the pull request', function () {
    createPreviewDeployment($this->application, $this->server, 7, str_repeat('a', 40));
    createPreviewDeployment($this->application, $this->server, 7, str_repeat('b', 40));
    createPreviewDeployment($this->application, $this->server, 8, str_repeat('c', 40));
    createPreviewDeployment($this->application, $this->server, 7, 'HEAD');
    $deployment = createPreviewDeployment($this->application, $this->server, 7, 'HEAD');

    $job = resolveBitbucketPullRequestCommit($deployment, $this->application);

    expect(deploymentJobCommit($job))->toBe(str_repeat('b', 40))
        ->and($deployment->fresh()->commit)->toBe(str_repeat('b', 40));
});

test('a manual bitbucket preview redeploy fails when no commit of the pull request is known', function () {
    createPreviewDeployment($this->application, $this->server, 8, str_repeat('c', 40));
    $deployment = createPreviewDeployment($this->application, $this->server, 7, 'HEAD');

    expect(fn () => resolveBitbucketPullRequestCommit($deployment, $this->application))
        ->toThrow(DeploymentException::class, 'Bitbucket pull request #7');

    expect($deployment->fresh()->commit)->toBe('HEAD');
});

test('a bitbucket preview deployment keeps the commit from the webhook', function () {
    createPreviewDeployment($this->application, $this->server, 7, str_repeat('a', 40));
    $deployment = createPreviewDeployment($this->application, $this->server, 7, str_repeat('d', 40));

    $job = resolveBitbucketPullRequestCommit($deployment, $this->application);

    expect(deploymentJobCommit($job))->toBe(str_repeat('d', 40));
});

test('other git providers resolve the pull request ref over git', function () {
    createPreviewDeployment($this->application, $this->server, 7, str_repeat('a', 40));
    $deployment = createPreviewDeployment($this->application, $this->server, 7, 'HEAD');

    $job = resolveBitbucketPullRequestCommit($deployment, $this->application, 'github');

    expect(deploymentJobCommit($job))->toBe('HEAD');
});
