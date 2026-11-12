<?php

use App\Http\Controllers\Webhook\Concerns\ValidatesPreviewDeploymentRepository;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ProcessGithubPullRequestWebhook;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\GiteaApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake([ApplicationDeploymentJob::class]);
    InstanceSettings::forceCreate(['id' => 0]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function forkFlagApplication(Team $team, array $attributes = []): Application
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $application = Application::create(array_merge([
        'name' => 'fork-flag-app',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ], $attributes));
    $application->settings->update([
        'is_preview_deployments_enabled' => true,
        'is_pr_deployments_public_enabled' => true,
    ]);

    return $application->refresh();
}

/**
 * Creates the application of a provider path and returns a sender that delivers
 * an opened pull request #7 from the same repository or from a fork.
 *
 * @return array{0: Application, 1: Closure(bool): mixed}
 */
function forkFlagProvider(string $provider): array
{
    $team = Team::factory()->create();

    return match ($provider) {
        'github job' => (function () use ($team) {
            $application = forkFlagApplication($team, ['git_repository' => 'https://github.com/example/repo']);

            return [$application, fn (bool $fork) => (new ProcessGithubPullRequestWebhook(
                applicationId: $application->id,
                githubAppId: null,
                action: 'opened',
                pullRequestId: 7,
                pullRequestHtmlUrl: 'https://github.com/example/repo/pull/7',
                pullRequestTitle: 'Add feature',
                beforeSha: null,
                afterSha: 'e83c5163316f89bfbde7d9ab23ca2e25604af290',
                commitSha: 'e83c5163316f89bfbde7d9ab23ca2e25604af290',
                fullName: 'example/repo',
                isForkPullRequest: $fork,
            ))->handle()];
        })(),
        'gitea app', 'gitea manual' => (function () use ($team, $provider) {
            $app = $provider === 'gitea app';
            $source = $app ? GiteaApp::create([
                'name' => 'gitea-fork-flag',
                'api_url' => 'https://gitea.example.com/api/v1',
                'html_url' => 'https://gitea.example.com',
                'webhook_secret' => 'gitea-secret',
                'team_id' => $team->id,
                'is_system_wide' => false,
                'is_public' => false,
            ]) : null;
            $application = forkFlagApplication($team, $app
                ? ['git_repository' => 'test-org/test-repo', 'source_id' => $source->id, 'source_type' => GiteaApp::class, 'repository_project_id' => 555]
                : ['git_repository' => 'https://gitea.example.com/test-org/test-repo']);

            return [$application, function (bool $fork) use ($application, $app) {
                $body = json_encode([
                    'action' => 'opened',
                    'number' => 7,
                    'repository' => ['id' => 555, 'full_name' => 'test-org/test-repo'],
                    'pull_request' => [
                        'html_url' => 'https://gitea.example.com/test-org/test-repo/pulls/7',
                        'title' => 'Add feature',
                        'head' => ['ref' => 'feature', 'sha' => 'e83c5163316f89bfbde7d9ab23ca2e25604af290', 'repo' => ['id' => $fork ? 999 : 555]],
                        'base' => ['ref' => 'main', 'repo' => ['id' => 555]],
                    ],
                ], JSON_THROW_ON_ERROR);

                return test()->call('POST', $app ? '/webhooks/source/gitea/events' : '/webhooks/source/gitea/events/manual', [], [], [], [
                    'HTTP_X-Gitea-Event' => 'pull_request',
                    ...($app
                        ? ['HTTP_X-Gitea-Signature' => hash_hmac('sha256', $body, 'gitea-secret')]
                        : ['HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $application->manual_webhook_secret_gitea)]),
                    'CONTENT_TYPE' => 'application/json',
                ], $body)->assertOk();
            }];
        })(),
        'gitlab app', 'gitlab manual' => (function () use ($team, $provider) {
            $app = $provider === 'gitlab app';
            $source = $app ? GitlabApp::create([
                'name' => 'gitlab-fork-flag',
                'api_url' => 'https://gitlab.com/api/v4',
                'html_url' => 'https://gitlab.com',
                'webhook_token' => 'gitlab-token',
                'team_id' => $team->id,
            ]) : null;
            $application = forkFlagApplication($team, $app
                ? ['git_repository' => 'https://gitlab.com/test-org/test-repo', 'source_id' => $source->id, 'source_type' => GitlabApp::class, 'repository_project_id' => 555]
                : ['git_repository' => 'https://gitlab.com/test-org/test-repo']);

            return [$application, fn (bool $fork) => test()->postJson($app ? '/webhooks/source/gitlab/events' : '/webhooks/source/gitlab/events/manual', [
                'object_kind' => 'merge_request',
                'project' => ['id' => 555, 'path_with_namespace' => 'test-org/test-repo'],
                'object_attributes' => [
                    'action' => 'open',
                    'iid' => 7,
                    'url' => 'https://gitlab.com/test-org/test-repo/-/merge_requests/7',
                    'title' => 'Add feature',
                    'source_branch' => 'feature',
                    'target_branch' => 'main',
                    'source_project_id' => $fork ? 999 : 555,
                    'target_project_id' => 555,
                    'last_commit' => ['id' => 'e83c5163316f89bfbde7d9ab23ca2e25604af290', 'message' => 'Add feature'],
                ],
            ], $app
                ? ['X-Gitlab-Token' => 'gitlab-token']
                : ['X-Gitlab-Event' => 'Merge Request Hook', 'X-Gitlab-Token' => $application->manual_webhook_secret_gitlab],
            )->assertOk()];
        })(),
        'bitbucket manual' => (function () use ($team) {
            $application = forkFlagApplication($team, ['git_repository' => 'https://bitbucket.org/test-org/test-repo']);

            return [$application, function (bool $fork) use ($application) {
                $body = json_encode([
                    'repository' => ['full_name' => 'test-org/test-repo', 'uuid' => '{target-uuid}'],
                    'pullrequest' => [
                        'id' => 7,
                        'title' => 'Add feature',
                        'links' => ['html' => ['href' => 'https://bitbucket.org/test-org/test-repo/pull-requests/7']],
                        'source' => [
                            'branch' => ['name' => 'feature'],
                            'commit' => ['hash' => 'e83c5163316f89bfbde7d9ab23ca2e25604af290'],
                            'repository' => ['uuid' => $fork ? '{fork-uuid}' : '{target-uuid}'],
                        ],
                        'destination' => [
                            'branch' => ['name' => 'main'],
                            'repository' => ['uuid' => '{target-uuid}'],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR);

                return test()->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], [
                    'HTTP_X-Event-Key' => 'pullrequest:created',
                    'HTTP_X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $body, $application->manual_webhook_secret_bitbucket),
                    'CONTENT_TYPE' => 'application/json',
                ], $body)->assertOk();
            }];
        })(),
    };
}

function forkFlagPreview(Application $application): ?ApplicationPreview
{
    return ApplicationPreview::query()->where('application_id', $application->id)->where('pull_request_id', 7)->first();
}

function forkFlagDeploymentQueued(Application $application): bool
{
    return ApplicationDeploymentQueue::query()->where('application_id', $application->id)->where('pull_request_id', 7)->exists();
}

dataset('fork flag providers', ['github job', 'gitea app', 'gitea manual', 'gitlab app', 'gitlab manual', 'bitbucket manual']);

it('marks a new preview of a pull request by whether it comes from a fork', function (string $provider, bool $fork) {
    [$application, $send] = forkFlagProvider($provider);

    $send($fork);

    expect(forkFlagPreview($application)?->is_fork_pull_request)->toBe($fork)
        ->and(forkFlagDeploymentQueued($application))->toBeTrue();
})->with('fork flag providers')->with(['fork' => true, 'same repository' => false]);

it('updates the fork flag of an existing preview', function (string $provider, bool $fork) {
    [$application, $send] = forkFlagProvider($provider);
    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 7,
        'pull_request_html_url' => 'https://example.com/pull/7',
    ]);
    $preview->forceFill(['is_fork_pull_request' => ! $fork])->save();

    $send($fork);

    expect(ApplicationPreview::query()->where('application_id', $application->id)->count())->toBe(1)
        ->and($preview->refresh()->is_fork_pull_request)->toBe($fork)
        ->and(forkFlagDeploymentQueued($application))->toBeTrue();
})->with('fork flag providers')->with(['fork' => true, 'same repository' => false]);

it('treats missing or malformed repository ids as a fork', function (mixed $source, mixed $target, bool $fork) {
    $checker = new class
    {
        use ValidatesPreviewDeploymentRepository;

        public function check(mixed $source, mixed $target): bool
        {
            return $this->isForkPreviewRepository($source, $target);
        }
    };

    expect($checker->check($source, $target))->toBe($fork);
})->with([
    'same id' => [555, ' 555 ', false],
    'other id' => [999, 555, true],
    'missing source' => [null, 555, true],
    'blank target' => [555, '  ', true],
    'array source' => [[555], 555, true],
]);
