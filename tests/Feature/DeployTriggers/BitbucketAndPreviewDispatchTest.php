<?php

use App\Jobs\EvaluatePush;
use App\Jobs\ProcessGithubPullRequestWebhook;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->fixture = deployTriggerFixture();
});

function bitbucketDispatchApp(array $fixture, array $attributes)
{
    $application = deployTriggerApplication($fixture, array_merge(['git_repository' => 'https://bitbucket.org/acme/mono'], $attributes));
    $application->forceFill(['manual_webhook_secret_bitbucket' => 'bb-secret'])->save();

    return $application;
}

function sendBitbucketDispatchPush($test, array $changes)
{
    $payload = json_encode(['push' => ['changes' => $changes], 'repository' => ['full_name' => 'acme/mono']]);

    return $test->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], [
        'HTTP_X-Event-Key' => 'repo:push',
        'HTTP_X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $payload, 'bb-secret'),
        'REMOTE_ADDR' => '203.0.113.30',
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}

function bitbucketDispatchChange(string $branch, string $hash): array
{
    return ['new' => ['type' => 'branch', 'name' => $branch, 'target' => ['hash' => $hash]], 'commits' => [['hash' => $hash, 'message' => 'Update']]];
}

it('defers bitbucket pushes per branch', function () {
    $main = bitbucketDispatchApp($this->fixture, ['git_branch' => 'main', 'base_directory' => '/apps/web']);
    $develop = bitbucketDispatchApp($this->fixture, ['git_branch' => 'develop', 'base_directory' => '/apps/web']);

    sendBitbucketDispatchPush($this, [bitbucketDispatchChange('main', str_repeat('a', 40)), bitbucketDispatchChange('develop', str_repeat('b', 40))])->assertOk();

    Queue::assertPushed(EvaluatePush::class, 2);
    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->applicationIds === [$main->id] && $job->context->ref === 'refs/heads/main' && $job->context->after === str_repeat('a', 40) && $job->context->provider === 'bitbucket');
    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->applicationIds === [$develop->id] && $job->context->ref === 'refs/heads/develop' && $job->context->payloadChangedFiles === null);
});

it('evaluates bitbucket applications with watch paths', function () {
    $web = bitbucketDispatchApp($this->fixture, ['git_branch' => 'main', 'watch_paths' => 'apps/web/**']);

    sendBitbucketDispatchPush($this, [bitbucketDispatchChange('main', str_repeat('a', 40))])->assertOk();

    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->applicationIds === [$web->id]);
    expect(ApplicationDeploymentQueue::query()->count())->toBe(0);
});

it('defers a github pull request opened event and creates no preview before the decision', function () {
    $web = deployTriggerApplication($this->fixture, ['git_repository' => 'acme/mono', 'base_directory' => '/apps/web']);
    $web->settings->update(['is_preview_deployments_enabled' => true]);

    (new ProcessGithubPullRequestWebhook(
        applicationId: $web->id, githubAppId: null, action: 'opened', pullRequestId: 12,
        pullRequestHtmlUrl: 'https://github.com/acme/mono/pull/12', pullRequestTitle: 'Change',
        beforeSha: null, afterSha: str_repeat('c', 40), commitSha: str_repeat('c', 40), fullName: 'acme/mono',
    ))->handle();

    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->applicationIds === [$web->id]
        && $job->context->ref === 'refs/pull/12/head'
        && $job->context->pullRequest?->number === 12
        && $job->context->pullRequest->targetBranch === 'main'
        && $job->context->pullRequest->gitType === 'github');
    expect(ApplicationPreview::query()->count())->toBe(0);
});

it('keeps fork previews on the existing approval flow', function () {
    $web = deployTriggerApplication($this->fixture, ['git_repository' => 'acme/mono', 'base_directory' => '/apps/web']);
    $web->settings->update(['is_preview_deployments_enabled' => true, 'is_pr_deployments_public_enabled' => true]);

    (new ProcessGithubPullRequestWebhook(
        applicationId: $web->id, githubAppId: null, action: 'opened', pullRequestId: 13,
        pullRequestHtmlUrl: 'https://github.com/acme/mono/pull/13', pullRequestTitle: 'Change',
        beforeSha: null, afterSha: str_repeat('d', 40), commitSha: str_repeat('d', 40), fullName: 'acme/mono', isForkPullRequest: true,
    ))->handle();

    Queue::assertNotPushed(EvaluatePush::class);
    expect(ApplicationPreview::query()->where('pull_request_id', 13)->sole()->is_fork_pull_request)->toBeTrue();
});
