<?php

use App\Actions\Application\CleanupPreviewDeployment;
use App\Jobs\ProcessGithubPullRequestWebhook;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
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

    $job = new class(applicationId: $this->application->id, githubAppId: null, action: 'closed', pullRequestId: 42, pullRequestHtmlUrl: 'https://github.com/example/repo/pull/42', pullRequestTitle: null, beforeSha: null, afterSha: null, commitSha: 'HEAD', fullName: 'example/repo') extends ProcessGithubPullRequestWebhook
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
        fullName: 'example/repo',
    );

    $job->handle();

    expect(ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBeFalse()
        ->and(ApplicationDeploymentQueue::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/example/repo/commits/head-sha');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/repos/example/repo/pulls/42/files'));
})->with(['opened', 'reopened']);

it('deploys an opened GitHub pull request preview when a watched file is beyond the first page of changed files', function () {
    Queue::fake();

    $this->application->update(['watch_paths' => 'apps/app/**']);
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

    $firstPage = collect(range(1, 100))->map(fn (int $i): array => ['filename' => ".github/workflows/workflow-{$i}.yml"])->all();

    Http::fake([
        'https://api.github.com/repos/example/repo/commits/head-sha' => Http::response([
            'commit' => ['message' => 'feat: add app'],
        ]),
        'https://api.github.com/repos/example/repo/pulls/42/files?per_page=100&page=1' => Http::response($firstPage),
        'https://api.github.com/repos/example/repo/pulls/42/files?per_page=100&page=2' => Http::response([
            ['filename' => 'apps/app/index.ts'],
        ]),
    ]);

    $job = new ProcessGithubPullRequestWebhook(
        applicationId: $this->application->id,
        githubAppId: $githubApp->id,
        action: 'opened',
        pullRequestId: 42,
        pullRequestHtmlUrl: 'https://github.com/example/repo/pull/42',
        pullRequestTitle: 'Add feature',
        beforeSha: null,
        afterSha: null,
        commitSha: 'head-sha',
        fullName: 'example/repo',
    );

    $job->handle();

    expect(ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBeTrue()
        ->and(ApplicationDeploymentQueue::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBeTrue();

    Http::assertSentCount(3);
});

it('deploys a GitHub pull request preview when the full list of changed files is unknown', function (string $action, array $filesResponse) {
    Queue::fake();

    $this->application->update(['watch_paths' => 'apps/app/**']);
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
        'https://api.github.com/repos/example/repo/commits/*' => Http::response([
            'commit' => ['message' => 'feat: add app'],
        ]),
        ...$filesResponse,
    ]);

    $job = new ProcessGithubPullRequestWebhook(
        applicationId: $this->application->id,
        githubAppId: $githubApp->id,
        action: $action,
        pullRequestId: 42,
        pullRequestHtmlUrl: 'https://github.com/example/repo/pull/42',
        pullRequestTitle: 'Add feature',
        beforeSha: 'before-sha',
        afterSha: 'after-sha',
        commitSha: 'after-sha',
        fullName: 'example/repo',
    );

    $job->handle();

    expect(ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBeTrue()
        ->and(ApplicationDeploymentQueue::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBeTrue();
})->with([
    'opened with a GitHub API error' => fn () => ['opened', [
        'https://api.github.com/repos/example/repo/pulls/42/files*' => Http::response(['message' => 'Server Error'], 500),
    ]],
    'synchronize with a GitHub API error' => fn () => ['synchronize', [
        'https://api.github.com/repos/example/repo/compare/*' => Http::response(['message' => 'Server Error'], 500),
    ]],
    'synchronize with a truncated compare file list' => fn () => ['synchronize', [
        'https://api.github.com/repos/example/repo/compare/*' => Http::response([
            'files' => collect(range(1, 300))->map(fn (int $i): array => ['filename' => "docs/page-{$i}.md"])->all(),
        ]),
    ]],
]);

it('skips an opened GitHub pull request preview when no changed file matches the watch paths', function () {
    Queue::fake();

    $this->application->update(['watch_paths' => 'apps/app/**']);
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
            'commit' => ['message' => 'docs: update readme'],
        ]),
        'https://api.github.com/repos/example/repo/pulls/42/files*' => Http::response([
            ['filename' => 'README.md'],
        ]),
    ]);

    $job = new ProcessGithubPullRequestWebhook(
        applicationId: $this->application->id,
        githubAppId: $githubApp->id,
        action: 'opened',
        pullRequestId: 42,
        pullRequestHtmlUrl: 'https://github.com/example/repo/pull/42',
        pullRequestTitle: 'Update docs',
        beforeSha: null,
        afterSha: null,
        commitSha: 'head-sha',
        fullName: 'example/repo',
    );

    $job->handle();

    expect(ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBeFalse()
        ->and(ApplicationDeploymentQueue::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBeFalse();
});

it('uses one GitHub App installation token for all GitHub API calls of a pull request job', function () {
    Queue::fake();

    $this->application->update(['watch_paths' => 'apps/app/**']);
    $this->application->settings->update([
        'is_preview_deployments_enabled' => true,
    ]);

    openssl_pkey_export(openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]), $pemKey);

    $githubApp = GithubApp::create([
        'name' => 'Private GitHub App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 12345,
        'installation_id' => 67890,
        'private_key_id' => PrivateKey::create([
            'name' => 'GitHub App Key',
            'private_key' => $pemKey,
            'team_id' => $this->team->id,
        ])->id,
        'is_public' => false,
        'team_id' => $this->team->id,
    ]);

    $firstPage = collect(range(1, 100))->map(fn (int $i): array => ['filename' => ".github/workflows/workflow-{$i}.yml"])->all();

    Http::fake([
        'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, [
            'Date' => now()->toRfc7231String(),
        ]),
        'https://api.github.com/app/installations/67890/access_tokens' => Http::response([
            'token' => 'fake-installation-token',
        ], 201),
        'https://api.github.com/repos/example/repo/commits/head-sha' => Http::response([
            'commit' => ['message' => 'feat: add app'],
        ]),
        'https://api.github.com/repos/example/repo/pulls/42/files?per_page=100&page=1' => Http::response($firstPage),
        'https://api.github.com/repos/example/repo/pulls/42/files?per_page=100&page=2' => Http::response([
            ['filename' => 'apps/app/index.ts'],
        ]),
    ]);

    $job = new ProcessGithubPullRequestWebhook(
        applicationId: $this->application->id,
        githubAppId: $githubApp->id,
        action: 'opened',
        pullRequestId: 42,
        pullRequestHtmlUrl: 'https://github.com/example/repo/pull/42',
        pullRequestTitle: 'Add feature',
        beforeSha: null,
        afterSha: null,
        commitSha: 'head-sha',
        fullName: 'example/repo',
    );

    $job->handle();

    expect(ApplicationDeploymentQueue::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBeTrue();

    $tokenRequests = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens'));
    $repositoryRequests = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/repos/example/repo/'));

    expect($tokenRequests)->toHaveCount(1)
        ->and($repositoryRequests)->toHaveCount(3)
        ->and($repositoryRequests->every(fn (array $pair): bool => $pair[0]->hasHeader('Authorization', 'Bearer fake-installation-token')))->toBeTrue();
});

it('deploys an opened GitHub pull request preview by fork status, not by author association', function (bool $isForkPullRequest, bool $isPublicEnabled, bool $deploys) {
    Queue::fake();

    $this->application->settings->update([
        'is_preview_deployments_enabled' => true,
        'is_pr_deployments_public_enabled' => $isPublicEnabled,
    ]);

    Http::fake([
        'https://api.github.com/repos/example/repo/commits/head-sha' => Http::response([
            'commit' => ['message' => 'feat: add app'],
        ]),
        'https://api.github.com/repos/example/repo/pulls/42/files*' => Http::response([
            ['filename' => 'README.md'],
        ]),
    ]);

    $job = new ProcessGithubPullRequestWebhook(
        applicationId: $this->application->id,
        githubAppId: GithubApp::create([
            'name' => 'Public GitHub',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'is_public' => true,
            'team_id' => $this->team->id,
        ])->id,
        action: 'opened',
        pullRequestId: 42,
        pullRequestHtmlUrl: 'https://github.com/example/repo/pull/42',
        pullRequestTitle: 'Add feature',
        beforeSha: null,
        afterSha: 'head-sha',
        commitSha: 'head-sha',
        fullName: 'example/repo',
        isForkPullRequest: $isForkPullRequest,
    );

    $job->handle();

    expect(ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBe($deploys)
        ->and(ApplicationDeploymentQueue::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBe($deploys);
})->with([
    'same-repository branch with public deployments off' => [false, false, true],
    'fork with public deployments off' => [true, false, false],
    'fork with public deployments on' => [true, true, true],
]);
