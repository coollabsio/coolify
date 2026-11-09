<?php

use App\Jobs\PlanEnvironmentConfigJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentConfigLink;
use App\Models\GithubApp;
use App\Services\ProjectConfig\ManagedResources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->fx = projectConfigFixture();
    $this->fx['server']->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->githubApp = GithubApp::create([
        'name' => 'acme-app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com',
        'app_id' => 4242, 'installation_id' => 99, 'webhook_secret' => 'app-secret', 'is_public' => false,
        'team_id' => $this->fx['team']->id,
    ]);
    $this->link = EnvironmentConfigLink::factory()->create([
        'environment_id' => $this->fx['environment']->id, 'mode' => 'git',
        'source_type' => GithubApp::class, 'source_id' => $this->githubApp->id,
        'repository' => 'acme/api', 'branch' => 'main', 'webhook_secret' => 'link-secret',
    ]);
});

function webhookApp(array $attributes = []): Application
{
    $fx = test()->fx;

    return ManagedResources::whileApplying(fn () => Application::forceCreate(array_merge([
        'name' => 'api', 'git_repository' => 'acme/api', 'git_branch' => 'main', 'build_pack' => 'dockerfile',
        'ports_exposes' => '3000', 'repository_project_id' => 777, 'source_id' => test()->githubApp->id, 'source_type' => GithubApp::class,
        'environment_id' => $fx['environment']->id, 'destination_id' => $fx['destination']->id, 'destination_type' => $fx['destination']->getMorphClass(),
    ], $attributes)));
}

function webhookPushPayload(string $branch = 'main', array $modified = ['coolify.json'], string $fullName = 'acme/api'): array
{
    return [
        'ref' => "refs/heads/{$branch}",
        'before' => str_repeat('1', 40),
        'after' => str_repeat('2', 40),
        'repository' => ['id' => 777, 'full_name' => $fullName],
        'installation' => ['id' => 99],
        'commits' => [['message' => 'Update', 'added' => [], 'removed' => [], 'modified' => $modified]],
    ];
}

function webhookGithubAppPush(array $payload, string $event = 'push')
{
    $body = json_encode($payload);

    return test()->call('POST', '/webhooks/source/github/events', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => $event,
        'HTTP_X_GITHUB_HOOK_INSTALLATION_TARGET_ID' => '4242',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
    ], $body);
}

function webhookGithubManualPush(array $payload, string $secret)
{
    $body = json_encode($payload);

    return test()->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => 'push',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body);
}

it('plans a push that changes only coolify.json, also with no matching application', function () {
    webhookGithubAppPush(webhookPushPayload())->assertOk();

    Queue::assertPushed(PlanEnvironmentConfigJob::class, fn ($job) => $job->link->is($this->link) && $job->commit === str_repeat('2', 40) && $job->heldApplicationUuids === []);
    expect($this->link->fresh()->pending_commit_sha)->toBe(str_repeat('2', 40));
});

it('holds the deployment of a managed application until the file is applied', function () {
    $app = webhookApp(['config_key' => 'api']);

    webhookGithubAppPush(webhookPushPayload(modified: ['src/index.js']))->assertOk();

    expect(ApplicationDeploymentQueue::where('application_id', $app->id)->count())->toBe(0);
    Queue::assertPushed(PlanEnvironmentConfigJob::class, fn ($job) => $job->heldApplicationUuids === [$app->uuid]);
});

it('deploys an unmanaged application as before', function () {
    $this->link->delete();
    $app = webhookApp();

    webhookGithubAppPush(webhookPushPayload(modified: ['src/index.js']))->assertOk();

    expect(ApplicationDeploymentQueue::where('application_id', $app->id)->count())->toBe(1);
    Queue::assertNotPushed(PlanEnvironmentConfigJob::class);
});

it('ignores a push to another branch and a pull request', function () {
    webhookGithubAppPush(webhookPushPayload('feature'))->assertOk();
    webhookGithubAppPush([
        'action' => 'opened', 'number' => 1, 'repository' => ['id' => 777, 'full_name' => 'acme/api'], 'installation' => ['id' => 99],
        'pull_request' => ['html_url' => 'https://github.com/acme/api/pull/1', 'title' => 'x', 'head' => ['ref' => 'feature', 'sha' => str_repeat('3', 40), 'repo' => ['full_name' => 'fork/api']], 'base' => ['ref' => 'main', 'repo' => ['full_name' => 'acme/api']]],
    ], 'pull_request')->assertOk();

    Queue::assertNotPushed(PlanEnvironmentConfigJob::class);
});

it('routes a manual webhook signed with the link secret, and fails closed without one', function () {
    $this->link->update(['source_type' => null, 'source_id' => null, 'repository' => 'https://github.com/acme/api.git']);

    webhookGithubManualPush(webhookPushPayload(), 'wrong-secret');
    Queue::assertNotPushed(PlanEnvironmentConfigJob::class);

    $this->link->update(['webhook_secret' => null]);
    webhookGithubManualPush(webhookPushPayload(), '');
    Queue::assertNotPushed(PlanEnvironmentConfigJob::class);

    $this->link->update(['webhook_secret' => 'link-secret']);
    webhookGithubManualPush(webhookPushPayload(), 'link-secret');
    Queue::assertPushed(PlanEnvironmentConfigJob::class, 1);
});

it('routes manual GitLab, Gitea, and Bitbucket pushes', function () {
    $this->link->update(['source_type' => null, 'source_id' => null, 'repository' => 'git@example.com:acme/api.git']);
    $gitlab = json_encode(['object_kind' => 'push', 'ref' => 'refs/heads/main', 'before' => str_repeat('1', 40), 'after' => str_repeat('4', 40), 'project' => ['path_with_namespace' => 'acme/api'], 'commits' => []]);
    $this->call('POST', '/webhooks/source/gitlab/events/manual', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GITLAB_TOKEN' => 'link-secret', 'HTTP_X_GITLAB_EVENT' => 'Push Hook'], $gitlab);
    $gitea = json_encode(webhookPushPayload());
    $this->call('POST', '/webhooks/source/gitea/events/manual', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GITEA_EVENT' => 'push', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $gitea, 'link-secret')], $gitea);
    $bitbucket = json_encode(['push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => str_repeat('5', 40)]], 'commits' => []]]], 'repository' => ['full_name' => 'acme/api']]);
    $this->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EVENT_KEY' => 'repo:push', 'HTTP_X_HUB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $bitbucket, 'link-secret')], $bitbucket);

    Queue::assertPushed(PlanEnvironmentConfigJob::class, 3);
});
