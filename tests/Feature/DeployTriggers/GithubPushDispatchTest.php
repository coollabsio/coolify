<?php

use App\Jobs\EvaluatePush;
use App\Models\ApplicationDeploymentQueue;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->fixture = deployTriggerFixture();
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
    $this->githubApp = GithubApp::create([
        'name' => 'dispatch-app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com',
        'app_id' => 555, 'installation_id' => 777, 'webhook_secret' => 'secret', 'is_public' => false,
        'private_key_id' => PrivateKey::create(['name' => 'k', 'private_key' => $pem, 'team_id' => $this->fixture['team']->id])->id,
        'team_id' => $this->fixture['team']->id,
    ]);
});

function githubDispatchApp(array $fixture, GithubApp $githubApp, array $attributes = [])
{
    return deployTriggerApplication($fixture, array_merge([
        'git_repository' => 'acme/mono', 'repository_project_id' => 4242,
        'source_id' => $githubApp->id, 'source_type' => GithubApp::class,
    ], $attributes));
}

function sendGithubDispatchPush($test, array $overrides = [])
{
    $body = json_encode(array_merge([
        'ref' => 'refs/heads/main',
        'before' => str_repeat('a', 40),
        'after' => str_repeat('b', 40),
        'forced' => false,
        'repository' => ['id' => 4242, 'full_name' => 'acme/mono'],
        'head_commit' => ['message' => 'feat: web'],
        'commits' => [['message' => 'feat: web', 'added' => [], 'removed' => [], 'modified' => ['apps/web/a.ts']]],
    ], $overrides), JSON_THROW_ON_ERROR);

    return $test->call('POST', '/webhooks/source/github/events', [], [], [], [
        'HTTP_X-GitHub-Event' => 'push',
        'HTTP_X-GitHub-Hook-Installation-Target-Id' => '555',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

it('dispatches one evaluation with the push details for narrowable apps', function () {
    $web = githubDispatchApp($this->fixture, $this->githubApp, ['base_directory' => '/apps/web']);
    $api = githubDispatchApp($this->fixture, $this->githubApp, ['base_directory' => '/apps/api']);

    $response = sendGithubDispatchPush($this);

    $response->assertOk();
    expect($response->getContent())->toContain('evaluating');
    expect(ApplicationDeploymentQueue::query()->count())->toBe(0);
    Queue::assertPushed(EvaluatePush::class, 1);
    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->applicationIds === [$web->id, $api->id]
        && $job->context->provider === 'github'
        && $job->context->forced === false
        && $job->context->before === str_repeat('a', 40)
        && $job->context->after === str_repeat('b', 40)
        && $job->context->ref === 'refs/heads/main'
        && $job->context->repositoryFullName === 'acme/mono'
        && $job->context->githubAppId === $this->githubApp->id
        && $job->context->teamId === $this->fixture['team']->id
        && $job->context->commitMessage === 'feat: web');
});

it('deploys a single app built from the repository root immediately', function () {
    $root = githubDispatchApp($this->fixture, $this->githubApp);

    sendGithubDispatchPush($this)->assertOk();

    Queue::assertNotPushed(EvaluatePush::class);
    expect(ApplicationDeploymentQueue::query()->where('application_id', $root->id)->exists())->toBeTrue();
});

it('honors skip ci before routing', function () {
    githubDispatchApp($this->fixture, $this->githubApp, ['base_directory' => '/apps/web']);

    sendGithubDispatchPush($this, ['commits' => [['message' => 'docs [skip ci]', 'added' => [], 'removed' => [], 'modified' => ['a']]]])->assertOk();

    Queue::assertNotPushed(EvaluatePush::class);
});

it('marks a forced push', function () {
    githubDispatchApp($this->fixture, $this->githubApp, ['base_directory' => '/apps/web']);

    sendGithubDispatchPush($this, ['forced' => true])->assertOk();

    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->context->forced === true);
});

it('dispatches manual github pushes as github_manual', function () {
    $web = deployTriggerApplication($this->fixture, ['git_repository' => 'https://github.com/acme/mono', 'base_directory' => '/apps/web', 'manual_webhook_secret_github' => 'manual-secret']);
    $body = json_encode([
        'ref' => 'refs/heads/main', 'before' => str_repeat('a', 40), 'after' => str_repeat('b', 40), 'forced' => false,
        'repository' => ['full_name' => 'acme/mono'], 'commits' => [],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
        'HTTP_X-GitHub-Event' => 'push',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'manual-secret'),
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => '203.0.113.20',
    ], $body)->assertOk();

    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->applicationIds === [$web->id]
        && $job->context->provider === 'github_manual'
        && $job->context->githubAppId === null
        && $job->context->payloadChangedFiles === []);
});
