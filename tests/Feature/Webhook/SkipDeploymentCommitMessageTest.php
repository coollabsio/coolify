<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createSkipTestApplication(string $repo = 'test-org/test-repo', string $branch = 'main'): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();

    return Application::create([
        'name' => 'skip-test-app',
        'git_repository' => "https://github.com/{$repo}",
        'git_branch' => $branch,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

// ---------------------------------------------------------------------------
// GitHub Manual Webhook
// ---------------------------------------------------------------------------
describe('GitHub manual webhook – skip deployment', function () {
    test('skips deployment when commit message contains [skip coolify]', function () {
        $app = createSkipTestApplication();
        $secret = $app->manual_webhook_secret_github;

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'abc1234',
            'commits' => [],
            'head_commit' => ['message' => 'Fix bug [skip coolify]'],
        ]);

        $hmac = hash_hmac('sha256', $payload, $secret);

        $response = $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => "sha256={$hmac}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('skipped');

        $queue = ApplicationDeploymentQueue::where('application_id', $app->id)->first();
        expect($queue)->not->toBeNull();
        expect($queue->status)->toBe(ApplicationDeploymentStatus::SKIPPED_BY_COMMIT_MESSAGE->value);
        expect($queue->commit_message)->toContain('[skip coolify]');
    });

    test('skips deployment when commit message contains [coolify skip]', function () {
        $app = createSkipTestApplication();
        $secret = $app->manual_webhook_secret_github;

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'abc1235',
            'commits' => [],
            'head_commit' => ['message' => '[coolify skip] chore: update deps'],
        ]);

        $hmac = hash_hmac('sha256', $payload, $secret);

        $response = $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => "sha256={$hmac}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('skipped');

        $queue = ApplicationDeploymentQueue::where('application_id', $app->id)->first();
        expect($queue->status)->toBe(ApplicationDeploymentStatus::SKIPPED_BY_COMMIT_MESSAGE->value);
    });

    test('skips deployment when skip tag is mixed case', function () {
        $app = createSkipTestApplication();
        $secret = $app->manual_webhook_secret_github;

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'abc1236',
            'commits' => [],
            'head_commit' => ['message' => 'ci: lint [SKIP COOLIFY]'],
        ]);

        $hmac = hash_hmac('sha256', $payload, $secret);

        $response = $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => "sha256={$hmac}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('skipped');
    });

    test('does not skip deployment without skip tag', function () {
        $app = createSkipTestApplication();
        $secret = $app->manual_webhook_secret_github;

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'abc1237',
            'commits' => [],
            'head_commit' => ['message' => 'feat: add new feature'],
        ]);

        $hmac = hash_hmac('sha256', $payload, $secret);

        $response = $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => "sha256={$hmac}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();

        $queue = ApplicationDeploymentQueue::where('application_id', $app->id)->first();
        expect($queue)->not->toBeNull();
        expect($queue->status)->not->toBe(ApplicationDeploymentStatus::SKIPPED_BY_COMMIT_MESSAGE->value);
    });
});

// ---------------------------------------------------------------------------
// GitLab Manual Webhook
// ---------------------------------------------------------------------------
describe('GitLab manual webhook – skip deployment', function () {
    test('skips deployment when commit message contains [skip coolify]', function () {
        $app = createSkipTestApplication();
        $secret = $app->manual_webhook_secret_gitlab;

        $response = $this->postJson('/webhooks/source/gitlab/events/manual', [
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'project' => ['path_with_namespace' => 'test-org/test-repo'],
            'after' => 'def1234',
            'commits' => [
                ['message' => 'hotfix [skip coolify]'],
            ],
        ], [
            'X-Gitlab-Token' => $secret,
        ]);

        $response->assertOk();
        expect($response->getContent())->toContain('skipped');

        $queue = ApplicationDeploymentQueue::where('application_id', $app->id)->first();
        expect($queue)->not->toBeNull();
        expect($queue->status)->toBe(ApplicationDeploymentStatus::SKIPPED_BY_COMMIT_MESSAGE->value);
    });

    test('skips deployment when commit message contains [coolify skip]', function () {
        $app = createSkipTestApplication();
        $secret = $app->manual_webhook_secret_gitlab;

        $response = $this->postJson('/webhooks/source/gitlab/events/manual', [
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'project' => ['path_with_namespace' => 'test-org/test-repo'],
            'after' => 'def1235',
            'commits' => [
                ['message' => '[coolify skip] docs: update readme'],
            ],
        ], [
            'X-Gitlab-Token' => $secret,
        ]);

        $response->assertOk();
        expect($response->getContent())->toContain('skipped');
    });

    test('does not skip deployment without skip tag', function () {
        $app = createSkipTestApplication();
        $secret = $app->manual_webhook_secret_gitlab;

        $response = $this->postJson('/webhooks/source/gitlab/events/manual', [
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'project' => ['path_with_namespace' => 'test-org/test-repo'],
            'after' => 'def1236',
            'commits' => [
                ['message' => 'feat: new login page'],
            ],
        ], [
            'X-Gitlab-Token' => $secret,
        ]);

        $response->assertOk();

        $queue = ApplicationDeploymentQueue::where('application_id', $app->id)->first();
        expect($queue)->not->toBeNull();
        expect($queue->status)->not->toBe(ApplicationDeploymentStatus::SKIPPED_BY_COMMIT_MESSAGE->value);
    });
});

// ---------------------------------------------------------------------------
// Gitea Manual Webhook
// ---------------------------------------------------------------------------
describe('Gitea manual webhook – skip deployment', function () {
    test('skips deployment when commit message contains [skip coolify]', function () {
        $app = createSkipTestApplication();
        $secret = $app->manual_webhook_secret_gitea;

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'ghi1234',
            'commits' => [],
            'head_commit' => ['message' => 'chore: bump version [skip coolify]'],
        ]);

        $hmac = hash_hmac('sha256', $payload, $secret);

        $response = $this->call('POST', '/webhooks/source/gitea/events/manual', [], [], [], [
            'HTTP_X-Gitea-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => "sha256={$hmac}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('skipped');

        $queue = ApplicationDeploymentQueue::where('application_id', $app->id)->first();
        expect($queue)->not->toBeNull();
        expect($queue->status)->toBe(ApplicationDeploymentStatus::SKIPPED_BY_COMMIT_MESSAGE->value);
    });

    test('skips deployment when commit message contains [coolify skip]', function () {
        $app = createSkipTestApplication();
        $secret = $app->manual_webhook_secret_gitea;

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'ghi1235',
            'commits' => [],
            'head_commit' => ['message' => '[coolify skip] test: fix flaky tests'],
        ]);

        $hmac = hash_hmac('sha256', $payload, $secret);

        $response = $this->call('POST', '/webhooks/source/gitea/events/manual', [], [], [], [
            'HTTP_X-Gitea-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => "sha256={$hmac}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('skipped');
    });

    test('does not skip deployment without skip tag', function () {
        $app = createSkipTestApplication();
        $secret = $app->manual_webhook_secret_gitea;

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'ghi1236',
            'commits' => [],
            'head_commit' => ['message' => 'fix: resolve null pointer'],
        ]);

        $hmac = hash_hmac('sha256', $payload, $secret);

        $response = $this->call('POST', '/webhooks/source/gitea/events/manual', [], [], [], [
            'HTTP_X-Gitea-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => "sha256={$hmac}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();

        $queue = ApplicationDeploymentQueue::where('application_id', $app->id)->first();
        expect($queue)->not->toBeNull();
        expect($queue->status)->not->toBe(ApplicationDeploymentStatus::SKIPPED_BY_COMMIT_MESSAGE->value);
    });
});

// ---------------------------------------------------------------------------
// Bitbucket Manual Webhook
// ---------------------------------------------------------------------------
describe('Bitbucket manual webhook – skip deployment', function () {
    test('skips deployment when commit message contains [skip coolify]', function () {
        $app = createSkipTestApplication();
        $secret = $app->manual_webhook_secret_bitbucket;

        $payload = json_encode([
            'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'jkl1234', 'message' => 'style: format code [skip coolify]']]]]],
            'repository' => ['full_name' => 'test-org/test-repo'],
        ]);

        $hmac = hash_hmac('sha256', $payload, $secret);

        $response = $this->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], [
            'HTTP_X-Event-Key' => 'repo:push',
            'HTTP_X-Hub-Signature' => "sha256={$hmac}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('skipped');

        $queue = ApplicationDeploymentQueue::where('application_id', $app->id)->first();
        expect($queue)->not->toBeNull();
        expect($queue->status)->toBe(ApplicationDeploymentStatus::SKIPPED_BY_COMMIT_MESSAGE->value);
    });

    test('skips deployment when commit message contains [coolify skip]', function () {
        $app = createSkipTestApplication();
        $secret = $app->manual_webhook_secret_bitbucket;

        $payload = json_encode([
            'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'jkl1235', 'message' => '[coolify skip] refactor: rename vars']]]]],
            'repository' => ['full_name' => 'test-org/test-repo'],
        ]);

        $hmac = hash_hmac('sha256', $payload, $secret);

        $response = $this->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], [
            'HTTP_X-Event-Key' => 'repo:push',
            'HTTP_X-Hub-Signature' => "sha256={$hmac}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('skipped');
    });

    test('does not skip deployment without skip tag', function () {
        $app = createSkipTestApplication();
        $secret = $app->manual_webhook_secret_bitbucket;

        $payload = json_encode([
            'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'jkl1236', 'message' => 'feat: dark mode support']]]]],
            'repository' => ['full_name' => 'test-org/test-repo'],
        ]);

        $hmac = hash_hmac('sha256', $payload, $secret);

        $response = $this->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], [
            'HTTP_X-Event-Key' => 'repo:push',
            'HTTP_X-Hub-Signature' => "sha256={$hmac}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();

        $queue = ApplicationDeploymentQueue::where('application_id', $app->id)->first();
        expect($queue)->not->toBeNull();
        expect($queue->status)->not->toBe(ApplicationDeploymentStatus::SKIPPED_BY_COMMIT_MESSAGE->value);
    });
});
