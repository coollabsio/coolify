<?php

use App\Jobs\ProcessGithubPullRequestWebhook;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\GiteaApp;
use App\Models\GitlabApp;
use App\Services\DeployTriggers\ClosedPullRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const CLOSURE_HEAD = 'cccccccccccccccccccccccccccccccccccccccc';

beforeEach(function () {
    Queue::fake();
    $this->fixture = deployTriggerFixture();
});

function closureApplication(array $fixture, string $provider): Application
{
    $attributes = ['base_directory' => '/apps/web', 'repository_project_id' => 31337];
    if ($provider === 'gitlab') {
        $source = GitlabApp::create(['name' => 'closure-gitlab', 'api_url' => 'https://gitlab.com/api/v4', 'html_url' => 'https://gitlab.com',
            'webhook_token' => 'closure-gitlab-token', 'team_id' => $fixture['team']->id]);
        $attributes += ['source_id' => $source->id, 'source_type' => GitlabApp::class];
    } elseif ($provider === 'gitea') {
        $source = GiteaApp::create(['name' => 'closure-gitea', 'api_url' => 'https://gitea.example.com/api/v1', 'html_url' => 'https://gitea.example.com',
            'webhook_secret' => 'closure-gitea-secret', 'team_id' => $fixture['team']->id]);
        $attributes += ['source_id' => $source->id, 'source_type' => GiteaApp::class];
    } elseif ($provider === 'gitlab_manual') {
        $attributes['git_repository'] = 'https://gitlab.com/acme/mono';
    } elseif ($provider === 'gitea_manual') {
        $attributes['git_repository'] = 'https://gitea.example.com/acme/mono';
    }
    $application = deployTriggerApplication($fixture, $attributes);
    $application->settings->update(['is_preview_deployments_enabled' => true]);

    return $application->refresh();
}

/**
 * @param  'open'|'update'|'reopen'|'close'  $event
 */
function sendPullRequestEvent(Application $application, string $provider, string $event): void
{
    if ($provider === 'github') {
        $action = ['open' => 'opened', 'update' => 'synchronize', 'reopen' => 'reopened', 'close' => 'closed'][$event];
        (new ProcessGithubPullRequestWebhook(
            applicationId: $application->id, githubAppId: null, action: $action, pullRequestId: 8,
            pullRequestHtmlUrl: 'https://github.com/acme/mono/pull/8', pullRequestTitle: 'Change',
            beforeSha: $event === 'update' ? str_repeat('d', 40) : null, afterSha: CLOSURE_HEAD, commitSha: CLOSURE_HEAD, fullName: 'acme/mono',
        ))->handle();

        return;
    }

    if (str_starts_with($provider, 'gitlab')) {
        $action = ['open' => 'open', 'update' => 'update', 'reopen' => 'reopen', 'close' => 'close'][$event];
        $payload = [
            'object_kind' => 'merge_request',
            'project' => ['id' => 31337, 'path_with_namespace' => 'acme/mono', 'web_url' => 'https://gitlab.com/acme/mono'],
            'object_attributes' => [
                'action' => $action, 'iid' => 8, 'url' => 'https://gitlab.com/acme/mono/-/merge_requests/8', 'title' => 'Change',
                'source_branch' => 'feature', 'target_branch' => 'main', 'source_project_id' => 31337, 'target_project_id' => 31337,
                'last_commit' => ['id' => CLOSURE_HEAD, 'message' => 'Change'],
            ] + ($event === 'update' ? ['oldrev' => str_repeat('d', 40)] : []),
        ];
        $provider === 'gitlab'
            ? test()->postJson('/webhooks/source/gitlab/events', $payload, ['X-Gitlab-Token' => 'closure-gitlab-token'])->assertOk()
            : test()->postJson('/webhooks/source/gitlab/events/manual', $payload, ['X-Gitlab-Event' => 'Merge Request Hook', 'X-Gitlab-Token' => $application->manual_webhook_secret_gitlab])->assertOk();

        return;
    }

    $action = ['open' => 'opened', 'update' => 'synchronized', 'reopen' => 'reopened', 'close' => 'closed'][$event];
    $body = json_encode(['action' => $action, 'number' => 8,
        'repository' => ['id' => 31337, 'full_name' => 'acme/mono', 'html_url' => 'https://gitea.example.com/acme/mono'],
        'pull_request' => [
            'html_url' => 'https://gitea.example.com/acme/mono/pulls/8', 'title' => 'Change',
            'head' => ['ref' => 'feature', 'sha' => CLOSURE_HEAD, 'repo' => ['id' => 31337]],
            'base' => ['ref' => 'main', 'repo' => ['id' => 31337]],
        ],
    ], JSON_THROW_ON_ERROR);
    $provider === 'gitea'
        ? test()->call('POST', '/webhooks/source/gitea/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Gitea-Event' => 'pull_request',
            'HTTP_X-Gitea-Signature' => hash_hmac('sha256', $body, 'closure-gitea-secret')], $body)->assertOk()
        : test()->call('POST', '/webhooks/source/gitea/events/manual', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Gitea-Event' => 'pull_request',
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $application->manual_webhook_secret_gitea)], $body)->assertOk();
}

dataset('pull request providers', ['github', 'gitlab', 'gitlab_manual', 'gitea', 'gitea_manual']);

it('drops the pending evaluation of a pull request that closes before its first deployment', function (string $provider) {
    $application = closureApplication($this->fixture, $provider);

    sendPullRequestEvent($application, $provider, 'open');
    sendPullRequestEvent($application, $provider, 'close');

    expect(runPushedEvaluations())->toBe(1)
        ->and(ApplicationPreview::query()->where('application_id', $application->id)->exists())->toBeFalse()
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
})->with('pull request providers');

it('keeps a pull request closed when an older update arrives after the close', function (string $provider) {
    $application = closureApplication($this->fixture, $provider);
    ClosedPullRequests::markClosed($application, 8);

    sendPullRequestEvent($application, $provider, 'update');

    expect(ClosedPullRequests::isClosed($application->id, 8))->toBeTrue();
    runPushedEvaluations();
    expect(ApplicationPreview::query()->where('application_id', $application->id)->exists())->toBeFalse();
})->with('pull request providers');

it('deploys a reopened pull request again', function (string $provider) {
    $application = closureApplication($this->fixture, $provider);
    ClosedPullRequests::markClosed($application, 8);

    sendPullRequestEvent($application, $provider, 'reopen');
    runPushedEvaluations();

    expect(ClosedPullRequests::isClosed($application->id, 8))->toBeFalse()
        ->and(ApplicationPreview::query()->where('application_id', $application->id)->where('pull_request_id', 8)->exists())->toBeTrue();
})->with('pull request providers');
