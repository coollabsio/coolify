<?php

use App\Actions\Application\CleanupPreviewDeployment;
use App\Jobs\ProcessGithubPullRequestWebhook;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

it('cleans up a closed pull request preview when pull request comment cleanup fails', function () {
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
    ]);

    CleanupPreviewDeployment::shouldRun()
        ->once()
        ->withArgs(fn (Application $application, int $pullRequestId, ApplicationPreview $applicationPreview): bool => $application->is($this->application)
            && $pullRequestId === 42
            && $applicationPreview->is($preview))
        ->andReturn([
            'cancelled_deployments' => 0,
            'killed_containers' => 0,
            'status' => 'success',
        ]);

    $job = new class(applicationId: $this->application->id, githubAppId: null, action: 'closed', pullRequestId: 42, pullRequestHtmlUrl: 'https://github.com/example/repo/pull/42', pullRequestTitle: null, beforeSha: null, afterSha: null, commitSha: 'HEAD', authorAssociation: 'OWNER', fullName: 'example/repo') extends ProcessGithubPullRequestWebhook
    {
        protected function dispatchPullRequestClosedUpdate(Application $application, ApplicationPreview $preview): void
        {
            throw new RuntimeException('GitHub comment cleanup failed.');
        }
    };

    $job->handle();
});
