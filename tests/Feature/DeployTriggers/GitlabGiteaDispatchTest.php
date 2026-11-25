<?php

use App\Http\Controllers\Webhook\Concerns\ReadsWebhookPushPayload;
use App\Jobs\EvaluatePush;
use App\Models\ApplicationPreview;
use App\Models\GiteaApp;
use App\Models\GitlabApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->fixture = deployTriggerFixture();
    $this->gitlabApp = GitlabApp::create([
        'name' => 'dispatch-gitlab', 'api_url' => 'https://gitlab.com/api/v4', 'html_url' => 'https://gitlab.com',
        'webhook_token' => 'gitlab-dispatch-token', 'team_id' => $this->fixture['team']->id,
    ]);
    $this->web = deployTriggerApplication($this->fixture, [
        'base_directory' => '/apps/web', 'repository_project_id' => 31337,
        'source_id' => $this->gitlabApp->id, 'source_type' => GitlabApp::class,
    ]);
    $this->web->settings->update(['is_preview_deployments_enabled' => true]);
});

function gitlabDispatchPush(array $overrides = []): array
{
    return array_merge([
        'object_kind' => 'push',
        'ref' => 'refs/heads/main',
        'before' => str_repeat('a', 40),
        'after' => str_repeat('b', 40),
        'project' => ['id' => 31337, 'path_with_namespace' => 'acme/mono', 'web_url' => 'https://gitlab.com/acme/mono'],
        'commits' => [['message' => 'feat', 'added' => [], 'removed' => [], 'modified' => ['apps/web/a.ts']]],
        'total_commits_count' => 1,
    ], $overrides);
}

function gitlabDispatchMergeRequest(string $action, array $attributes = []): array
{
    return [
        'object_kind' => 'merge_request',
        'project' => ['id' => 31337, 'path_with_namespace' => 'acme/mono', 'web_url' => 'https://gitlab.com/acme/mono'],
        'object_attributes' => array_merge([
            'action' => $action, 'iid' => 8, 'url' => 'https://gitlab.com/acme/mono/-/merge_requests/8', 'title' => 'Change',
            'source_branch' => 'feature', 'target_branch' => 'main', 'source_project_id' => 31337, 'target_project_id' => 31337,
            'last_commit' => ['id' => str_repeat('c', 40), 'message' => 'Change'],
        ], $attributes),
    ];
}

it('defers a gitlab push to evaluation with its push details', function () {
    $this->postJson('/webhooks/source/gitlab/events', gitlabDispatchPush(), ['X-Gitlab-Token' => 'gitlab-dispatch-token'])->assertOk();

    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->applicationIds === [$this->web->id]
        && $job->context->provider === 'gitlab'
        && $job->context->repositoryUrl === 'https://gitlab.com/acme/mono'
        && $job->context->before === str_repeat('a', 40)
        && $job->context->payloadChangedFiles === ['apps/web/a.ts']);
});

it('treats a gitlab push with more than 20 commits as an unknown file list', function () {
    $this->postJson('/webhooks/source/gitlab/events', gitlabDispatchPush(['total_commits_count' => 25]), ['X-Gitlab-Token' => 'gitlab-dispatch-token'])->assertOk();

    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->context->payloadChangedFiles === null);
});

it('reads a truncated commit list as unknown', function () {
    $reader = new class
    {
        use ReadsWebhookPushPayload;

        public function files(array $payload): ?array
        {
            return $this->webhookPushChangedFiles($payload)?->all();
        }
    };
    $commits = [['added' => ['a'], 'removed' => [], 'modified' => []]];

    expect($reader->files(['commits' => $commits, 'total_commits_count' => 21]))->toBeNull()
        ->and($reader->files(['commits' => $commits, 'total_commits' => 9]))->toBeNull()
        ->and($reader->files(['commits' => $commits, 'total_commits_count' => 1]))->toBe(['a']);
});

it('defers a gitlab merge request update that changes code', function () {
    $this->postJson('/webhooks/source/gitlab/events', gitlabDispatchMergeRequest('update', ['oldrev' => str_repeat('d', 40)]), ['X-Gitlab-Token' => 'gitlab-dispatch-token'])->assertOk();

    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->context->ref === 'refs/merge-requests/8/head'
        && $job->context->pullRequest?->number === 8
        && $job->context->pullRequest->targetBranch === 'main'
        && $job->context->pullRequest->isFork === false
        && $job->context->after === str_repeat('c', 40));
});

it('keeps deploying a gitlab merge request update without new commits as before', function () {
    $this->postJson('/webhooks/source/gitlab/events', gitlabDispatchMergeRequest('update'), ['X-Gitlab-Token' => 'gitlab-dispatch-token'])->assertOk();

    Queue::assertNotPushed(EvaluatePush::class);
    expect(ApplicationPreview::query()->where('application_id', $this->web->id)->where('pull_request_id', 8)->exists())->toBeTrue();
});

it('defers a gitea push and a pull request synchronize', function () {
    $giteaApp = GiteaApp::create([
        'name' => 'dispatch-gitea', 'api_url' => 'https://gitea.example.com/api/v1', 'html_url' => 'https://gitea.example.com',
        'webhook_secret' => 'gitea-secret', 'team_id' => $this->fixture['team']->id,
    ]);
    $app = deployTriggerApplication($this->fixture, ['base_directory' => '/apps/api', 'repository_project_id' => 55, 'source_id' => $giteaApp->id, 'source_type' => GiteaApp::class]);
    $app->settings->update(['is_preview_deployments_enabled' => true]);
    $send = function (string $event, array $payload) {
        $body = json_encode($payload);

        return $this->call('POST', '/webhooks/source/gitea/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-Gitea-Event' => $event,
            'HTTP_X-Gitea-Signature' => hash_hmac('sha256', $body, 'gitea-secret'),
        ], $body);
    };
    $repository = ['id' => 55, 'full_name' => 'acme/mono', 'html_url' => 'https://gitea.example.com/acme/mono'];

    $send('push', ['ref' => 'refs/heads/main', 'before' => str_repeat('a', 40), 'after' => str_repeat('b', 40), 'repository' => $repository, 'commits' => [], 'total_commits' => 0])->assertOk();
    $send('pull_request', ['action' => 'synchronized', 'number' => 4, 'repository' => $repository, 'pull_request' => [
        'html_url' => 'https://gitea.example.com/acme/mono/pulls/4', 'title' => 'x',
        'head' => ['ref' => 'feature', 'sha' => str_repeat('e', 40), 'repo' => ['id' => 55]],
        'base' => ['ref' => 'main', 'repo' => ['id' => 55]],
    ]])->assertOk();

    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->context->provider === 'gitea' && $job->context->pullRequest === null);
    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->context->ref === 'refs/pull/4/head' && $job->context->after === str_repeat('e', 40));
});
