<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ProcessGithubPullRequestWebhook;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'test-network-'.fake()->unique()->word(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function makePreviewApp(int $environmentId, int $destinationId, bool $autoDeploy): Application
{
    $application = Application::factory()->create([
        'environment_id' => $environmentId,
        'destination_id' => $destinationId,
        'destination_type' => StandaloneDocker::class,
        'git_commit_sha' => 'HEAD',
    ]);

    $application->settings->update([
        'is_preview_deployments_enabled' => true,
        'is_preview_auto_deploy_enabled' => $autoDeploy,
    ]);

    return $application;
}

// Use action 'synchronize' with null before/after shas so the handler does not
// reach out to the GitHub API for watch-path file diffing.
function dispatchOpenWebhook(Application $application, int $pullRequestId): void
{
    (new ProcessGithubPullRequestWebhook(
        applicationId: $application->id,
        githubAppId: null,
        action: 'synchronize',
        pullRequestId: $pullRequestId,
        pullRequestHtmlUrl: "https://github.com/owner/repo/pull/{$pullRequestId}",
        pullRequestTitle: 'feat: add things',
        beforeSha: null,
        afterSha: null,
        commitSha: 'abc123def456abc123def456abc123def456abc1',
        authorAssociation: 'OWNER',
        fullName: 'owner/repo',
        isForkPullRequest: false,
    ))->handle();
}

describe('isPRAutoDeployable accessor', function () {
    test('reflects the is_preview_auto_deploy_enabled setting', function () {
        $application = makePreviewApp($this->environment->id, $this->destination->id, autoDeploy: true);
        expect($application->isPRAutoDeployable())->toBeTrue();

        $application->settings->update(['is_preview_auto_deploy_enabled' => false]);
        expect($application->fresh()->isPRAutoDeployable())->toBeFalse();
    });
});

describe('preview auto deploy gating (github webhook)', function () {
    test('creates the preview but does NOT queue a deployment when auto deploy is off', function () {
        $application = makePreviewApp($this->environment->id, $this->destination->id, autoDeploy: false);

        dispatchOpenWebhook($application, 101);

        expect(ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', 101)->exists())->toBeTrue();
        expect(ApplicationDeploymentQueue::where('application_id', $application->id)->where('pull_request_id', 101)->count())->toBe(0);
    });

    test('creates the preview AND queues a deployment when auto deploy is on', function () {
        $application = makePreviewApp($this->environment->id, $this->destination->id, autoDeploy: true);

        dispatchOpenWebhook($application, 202);

        expect(ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', 202)->exists())->toBeTrue();
        expect(ApplicationDeploymentQueue::where('application_id', $application->id)->where('pull_request_id', 202)->count())->toBe(1);
    });
});
