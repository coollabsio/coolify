<?php

use App\Actions\Application\CleanupPreviewDeployment;
use App\Jobs\ProcessGithubPullRequestWebhook;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

it('skips a synchronized GitHub pull request preview when the head commit message contains skip ci', function () {
    Queue::fake();

    $this->application->settings->update([
        'is_preview_deployments_enabled' => true,
    ]);

    $githubApp = GithubApp::create([
        'name' => 'Public GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => true,
        'team_id' => $this->team->id,
    ]);

    Http::fake([
        'https://api.github.com/repos/example/repo/commits/after-sha' => Http::response([
            'commit' => [
                'message' => 'docs: fix typo [skip ci]',
            ],
        ]),
    ]);

    $job = new ProcessGithubPullRequestWebhook(
        applicationId: $this->application->id,
        githubAppId: $githubApp->id,
        action: 'synchronize',
        pullRequestId: 42,
        pullRequestHtmlUrl: 'https://github.com/example/repo/pull/42',
        pullRequestTitle: 'Add feature',
        beforeSha: 'before-sha',
        afterSha: 'after-sha',
        commitSha: 'after-sha',
        authorAssociation: 'OWNER',
        fullName: 'example/repo',
    );

    $job->handle();

    expect(ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBeFalse()
        ->and(ApplicationDeploymentQueue::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/example/repo/commits/after-sha');
});

it('deploys a synchronized GitHub pull request preview when the head commit message does not contain skip ci', function () {
    Queue::fake();

    $this->application->settings->update([
        'is_preview_deployments_enabled' => true,
    ]);

    $githubApp = GithubApp::create([
        'name' => 'Public GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => true,
        'team_id' => $this->team->id,
    ]);

    Http::fake([
        'https://api.github.com/repos/example/repo/commits/after-sha' => Http::response([
            'commit' => [
                'message' => 'docs: fix typo',
            ],
        ]),
        'https://api.github.com/repos/example/repo/compare/before-sha...after-sha' => Http::response([
            'files' => [
                ['filename' => 'README.md'],
            ],
        ]),
    ]);

    $job = new ProcessGithubPullRequestWebhook(
        applicationId: $this->application->id,
        githubAppId: $githubApp->id,
        action: 'synchronize',
        pullRequestId: 42,
        pullRequestHtmlUrl: 'https://github.com/example/repo/pull/42',
        pullRequestTitle: 'Add feature',
        beforeSha: 'before-sha',
        afterSha: 'after-sha',
        commitSha: 'after-sha',
        authorAssociation: 'OWNER',
        fullName: 'example/repo',
    );

    $job->handle();

    expect(ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBeTrue()
        ->and(ApplicationDeploymentQueue::where('application_id', $this->application->id)->where('pull_request_id', 42)->where('commit', 'after-sha')->exists())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/example/repo/commits/after-sha');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/example/repo/compare/before-sha...after-sha');
});

it('skips a GitHub pull request preview when the opened or reopened head commit message contains skip ci', function (string $action) {
    Queue::fake();

    $this->application->settings->update([
        'is_preview_deployments_enabled' => true,
    ]);

    $githubApp = GithubApp::create([
        'name' => 'Public GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => true,
        'team_id' => $this->team->id,
    ]);

    Http::fake([
        'https://api.github.com/repos/example/repo/commits/head-sha' => Http::response([
            'commit' => [
                'message' => 'docs: fix typo [skip ci]',
            ],
        ]),
        'https://api.github.com/repos/example/repo/pulls/42/files' => Http::response([]),
    ]);

    $job = new ProcessGithubPullRequestWebhook(
        applicationId: $this->application->id,
        githubAppId: $githubApp->id,
        action: $action,
        pullRequestId: 42,
        pullRequestHtmlUrl: 'https://github.com/example/repo/pull/42',
        pullRequestTitle: 'Add feature',
        beforeSha: null,
        afterSha: null,
        commitSha: 'head-sha',
        authorAssociation: 'OWNER',
        fullName: 'example/repo',
    );

    $job->handle();

    expect(ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBeFalse()
        ->and(ApplicationDeploymentQueue::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/example/repo/commits/head-sha');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/example/repo/pulls/42/files');
})->with(['opened', 'reopened']);
