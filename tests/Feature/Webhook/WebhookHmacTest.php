<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function createApplicationWithWebhook(string $repo = 'test-org/test-repo', string $branch = 'main', array $overrides = []): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();

    return Application::create(array_merge([
        'name' => 'webhook-test-app',
        'git_repository' => "https://github.com/{$repo}",
        'git_branch' => $branch,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ], $overrides));
}

describe('GitHub Manual Webhook HMAC', function () {
    test('rejects push when secret is empty', function () {
        $app = createApplicationWithWebhook();
        DB::table('applications')->where('id', $app->id)->update([
            'manual_webhook_secret_github' => null,
        ]);

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'abc123',
            'commits' => [],
        ]);

        $response = $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, ''),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('Invalid signature');
    });

    test('rejects push with forged hash', function () {
        $app = createApplicationWithWebhook();

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'abc123',
            'commits' => [],
        ]);

        $response = $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => 'sha256=forgedhashvalue',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('Invalid signature');
    });

    test('accepts push with valid hash', function () {
        $app = createApplicationWithWebhook();
        $secret = $app->manual_webhook_secret_github;

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'abc123',
            'commits' => [],
        ]);

        $hmac = hash_hmac('sha256', $payload, $secret);

        $response = $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => "sha256={$hmac}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        $content = $response->getContent();
        expect($content)->not->toContain('Invalid signature');
        expect($content)->not->toContain('Webhook secret not configured');
    });
});

describe('GitLab Manual Webhook HMAC', function () {
    test('rejects push when secret is empty', function () {
        $app = createApplicationWithWebhook();
        DB::table('applications')->where('id', $app->id)->update([
            'manual_webhook_secret_gitlab' => null,
        ]);

        $response = $this->postJson('/webhooks/source/gitlab/events/manual', [
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'project' => ['path_with_namespace' => 'test-org/test-repo'],
            'after' => 'abc123',
            'commits' => [],
        ], [
            'X-Gitlab-Token' => 'attacker-supplied-token',
        ]);

        $response->assertOk();
        expect($response->getContent())->toContain('Invalid signature');
    });

    test('rejects push with wrong token', function () {
        $app = createApplicationWithWebhook();

        $response = $this->postJson('/webhooks/source/gitlab/events/manual', [
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'project' => ['path_with_namespace' => 'test-org/test-repo'],
            'after' => 'abc123',
            'commits' => [],
        ], [
            'X-Gitlab-Token' => 'wrong-token',
        ]);

        $response->assertOk();
        expect($response->getContent())->toContain('Invalid signature');
    });

    test('accepts push with valid token', function () {
        $app = createApplicationWithWebhook();
        $secret = $app->manual_webhook_secret_gitlab;

        $response = $this->postJson('/webhooks/source/gitlab/events/manual', [
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'project' => ['path_with_namespace' => 'test-org/test-repo'],
            'after' => 'abc123',
            'commits' => [],
        ], [
            'X-Gitlab-Token' => $secret,
        ]);

        $response->assertOk();
        $content = $response->getContent();
        expect($content)->not->toContain('Invalid signature');
        expect($content)->not->toContain('Webhook secret not configured');
    });
});

describe('Bitbucket Manual Webhook HMAC', function () {
    test('rejects push when secret is empty', function () {
        $app = createApplicationWithWebhook();
        DB::table('applications')->where('id', $app->id)->update([
            'manual_webhook_secret_bitbucket' => null,
        ]);

        $payload = json_encode([
            'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'abc123']]]]],
            'repository' => ['full_name' => 'test-org/test-repo'],
        ]);

        $response = $this->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], [
            'HTTP_X-Event-Key' => 'repo:push',
            'HTTP_X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $payload, ''),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('Invalid signature');
    });

    test('rejects push with non-sha256 algorithm', function () {
        $app = createApplicationWithWebhook();
        $secret = $app->manual_webhook_secret_bitbucket;

        $payload = json_encode([
            'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'abc123']]]]],
            'repository' => ['full_name' => 'test-org/test-repo'],
        ]);

        $response = $this->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], [
            'HTTP_X-Event-Key' => 'repo:push',
            'HTTP_X-Hub-Signature' => 'sha1='.hash_hmac('sha1', $payload, $secret),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('Invalid signature');
    });

    test('rejects push with forged hash', function () {
        $app = createApplicationWithWebhook();

        $payload = json_encode([
            'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'abc123']]]]],
            'repository' => ['full_name' => 'test-org/test-repo'],
        ]);

        $response = $this->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], [
            'HTTP_X-Event-Key' => 'repo:push',
            'HTTP_X-Hub-Signature' => 'sha256=forgedhashvalue',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('Invalid signature');
    });

    test('accepts push with valid sha256 hash', function () {
        $app = createApplicationWithWebhook();
        $secret = $app->manual_webhook_secret_bitbucket;

        $payload = json_encode([
            'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'abc123']]]]],
            'repository' => ['full_name' => 'test-org/test-repo'],
        ]);

        $hmac = hash_hmac('sha256', $payload, $secret);

        $response = $this->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], [
            'HTTP_X-Event-Key' => 'repo:push',
            'HTTP_X-Hub-Signature' => "sha256={$hmac}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        $content = $response->getContent();
        expect($content)->not->toContain('Invalid signature');
        expect($content)->not->toContain('Webhook secret not configured');
    });
});

describe('Gitea Manual Webhook HMAC', function () {
    test('rejects push when secret is empty', function () {
        $app = createApplicationWithWebhook();
        DB::table('applications')->where('id', $app->id)->update([
            'manual_webhook_secret_gitea' => null,
        ]);

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'abc123',
            'commits' => [],
        ]);

        $response = $this->call('POST', '/webhooks/source/gitea/events/manual', [], [], [], [
            'HTTP_X-Gitea-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, ''),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('Invalid signature');
    });

    test('rejects push with forged hash', function () {
        $app = createApplicationWithWebhook();

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'abc123',
            'commits' => [],
        ]);

        $response = $this->call('POST', '/webhooks/source/gitea/events/manual', [], [], [], [
            'HTTP_X-Gitea-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => 'sha256=forgedhashvalue',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('Invalid signature');
    });

    test('accepts push with valid hash', function () {
        $app = createApplicationWithWebhook();
        $secret = $app->manual_webhook_secret_gitea;

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'abc123',
            'commits' => [],
        ]);

        $hmac = hash_hmac('sha256', $payload, $secret);

        $response = $this->call('POST', '/webhooks/source/gitea/events/manual', [], [], [], [
            'HTTP_X-Gitea-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => "sha256={$hmac}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        $content = $response->getContent();
        expect($content)->not->toContain('Invalid signature');
        expect($content)->not->toContain('Webhook secret not configured');
    });
});

describe('Manual Webhook Repository Matching', function () {
    test('github rejects empty repository without leaking applications', function () {
        $app = createApplicationWithWebhook(overrides: ['name' => 'secret-github-app']);

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => ''],
            'after' => 'abc123',
            'commits' => [],
        ]);

        $response = $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => 'sha256=forgedhashvalue',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        $content = $response->getContent();
        expect($content)->toContain('Invalid repository')
            ->not->toContain('secret-github-app')
            ->not->toContain($app->uuid);
    });

    test('github does not match repository substrings', function () {
        $app = createApplicationWithWebhook(overrides: ['name' => 'secret-github-app']);

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test'],
            'after' => 'abc123',
            'commits' => [],
        ]);

        $response = $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => 'sha256=forgedhashvalue',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        $content = $response->getContent();
        expect($content)->toContain('No applications found')
            ->not->toContain('secret-github-app')
            ->not->toContain($app->uuid);
    });

    test('github invalid signature does not leak matched application identifiers', function () {
        $app = createApplicationWithWebhook(overrides: ['name' => 'secret-github-app']);

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'abc123',
            'commits' => [],
        ]);

        $response = $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => 'sha256=forgedhashvalue',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        $content = $response->getContent();
        expect($content)->toContain('Invalid signature')
            ->not->toContain('secret-github-app')
            ->not->toContain($app->uuid)
            ->not->toContain('application_uuid')
            ->not->toContain('application_name');
    });

    test('manual webhooks reject empty repositories for every provider without leaking applications', function (string $provider, string $uri, array $payload, array $headers) {
        $app = createApplicationWithWebhook(overrides: ['name' => "secret-{$provider}-app"]);
        $body = json_encode($payload);

        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server[$name] = $value;
        }

        $response = $this->call('POST', $uri, [], [], [], $server, $body);

        $response->assertOk();
        $content = $response->getContent();
        expect($content)->toContain('Invalid repository')
            ->not->toContain("secret-{$provider}-app")
            ->not->toContain($app->uuid);
    })->with([
        'gitlab' => [
            'gitlab',
            '/webhooks/source/gitlab/events/manual',
            [
                'object_kind' => 'push',
                'ref' => 'refs/heads/main',
                'project' => ['path_with_namespace' => ''],
                'after' => 'abc123',
                'commits' => [],
            ],
            ['HTTP_X-Gitlab-Token' => 'wrong-token'],
        ],
        'bitbucket' => [
            'bitbucket',
            '/webhooks/source/bitbucket/events/manual',
            [
                'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'abc123']]]]],
                'repository' => ['full_name' => ''],
            ],
            ['HTTP_X-Event-Key' => 'repo:push', 'HTTP_X-Hub-Signature' => 'sha256=forgedhashvalue'],
        ],
        'gitea' => [
            'gitea',
            '/webhooks/source/gitea/events/manual',
            [
                'ref' => 'refs/heads/main',
                'repository' => ['full_name' => ''],
                'after' => 'abc123',
                'commits' => [],
            ],
            ['HTTP_X-Gitea-Event' => 'push', 'HTTP_X-Hub-Signature-256' => 'sha256=forgedhashvalue'],
        ],
    ]);

    test('manual webhooks do not match repository substrings for every provider', function (string $provider, string $uri, array $payload, array $headers) {
        $app = createApplicationWithWebhook(overrides: ['name' => "secret-{$provider}-app"]);
        $body = json_encode($payload);

        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server[$name] = $value;
        }

        $response = $this->call('POST', $uri, [], [], [], $server, $body);

        $response->assertOk();
        $content = $response->getContent();
        expect($content)->toContain('No applications found')
            ->not->toContain("secret-{$provider}-app")
            ->not->toContain($app->uuid);
    })->with([
        'gitlab' => [
            'gitlab',
            '/webhooks/source/gitlab/events/manual',
            [
                'object_kind' => 'push',
                'ref' => 'refs/heads/main',
                'project' => ['path_with_namespace' => 'test-org/test'],
                'after' => 'abc123',
                'commits' => [],
            ],
            ['HTTP_X-Gitlab-Token' => 'wrong-token'],
        ],
        'bitbucket' => [
            'bitbucket',
            '/webhooks/source/bitbucket/events/manual',
            [
                'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'abc123']]]]],
                'repository' => ['full_name' => 'test-org/test'],
            ],
            ['HTTP_X-Event-Key' => 'repo:push', 'HTTP_X-Hub-Signature' => 'sha256=forgedhashvalue'],
        ],
        'gitea' => [
            'gitea',
            '/webhooks/source/gitea/events/manual',
            [
                'ref' => 'refs/heads/main',
                'repository' => ['full_name' => 'test-org/test'],
                'after' => 'abc123',
                'commits' => [],
            ],
            ['HTTP_X-Gitea-Event' => 'push', 'HTTP_X-Hub-Signature-256' => 'sha256=forgedhashvalue'],
        ],
    ]);

    test('github matches ssh git repository URL exactly', function () {
        $app = createApplicationWithWebhook(overrides: [
            'git_repository' => 'git@github.com:test-org/test-repo.git',
        ]);
        $secret = $app->manual_webhook_secret_github;

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'abc123',
            'commits' => [],
        ]);

        $response = $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, $secret),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->not->toContain('No applications found');
    });

    test('gitlab matches scp-style ssh repository URL with custom port', function () {
        $app = createApplicationWithWebhook(overrides: [
            'git_repository' => 'git@gitlab.example.com:2222/services/xyz.git',
            'git_branch' => 'master',
        ]);
        $secret = $app->manual_webhook_secret_gitlab;

        $response = $this->postJson('/webhooks/source/gitlab/events/manual', [
            'object_kind' => 'push',
            'ref' => 'refs/heads/master',
            'project' => ['path_with_namespace' => 'services/xyz'],
            'after' => 'abc123',
            'commits' => [],
        ], [
            'X-Gitlab-Token' => $secret,
        ]);

        $response->assertOk();
        expect($response->getContent())->not->toContain('No applications found');
    });

    test('gitlab matches scp-style ssh repository URL without port', function () {
        $app = createApplicationWithWebhook(overrides: [
            'git_repository' => 'git@gitlab.example.com:services/xyz.git',
            'git_branch' => 'master',
        ]);
        $secret = $app->manual_webhook_secret_gitlab;

        $response = $this->postJson('/webhooks/source/gitlab/events/manual', [
            'object_kind' => 'push',
            'ref' => 'refs/heads/master',
            'project' => ['path_with_namespace' => 'services/xyz'],
            'after' => 'abc123',
            'commits' => [],
        ], [
            'X-Gitlab-Token' => $secret,
        ]);

        $response->assertOk();
        expect($response->getContent())->not->toContain('No applications found');
    });

    test('github matches repository case-insensitively', function () {
        $app = createApplicationWithWebhook(overrides: [
            'git_repository' => 'https://github.com/Test-Org/Test-Repo.git',
        ]);
        $secret = $app->manual_webhook_secret_github;

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'test-org/test-repo'],
            'after' => 'abc123',
            'commits' => [],
        ]);

        $response = $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, $secret),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->not->toContain('No applications found');
    });
});

describe('Webhook Secret Auto-Generation', function () {
    test('auto-generates webhook secrets on application creation', function () {
        $app = createApplicationWithWebhook();

        expect($app->manual_webhook_secret_github)->not->toBeEmpty();
        expect($app->manual_webhook_secret_gitlab)->not->toBeEmpty();
        expect($app->manual_webhook_secret_bitbucket)->not->toBeEmpty();
        expect($app->manual_webhook_secret_gitea)->not->toBeEmpty();
        expect(strlen($app->manual_webhook_secret_github))->toBe(40);
        expect(strlen($app->manual_webhook_secret_gitlab))->toBe(40);
        expect(strlen($app->manual_webhook_secret_bitbucket))->toBe(40);
        expect(strlen($app->manual_webhook_secret_gitea))->toBe(40);
    });

    test('encrypts webhook secrets at rest', function () {
        $app = createApplicationWithWebhook();
        $plaintext = $app->manual_webhook_secret_github;

        $raw = DB::table('applications')->where('id', $app->id)->first();

        expect($raw->manual_webhook_secret_github)->not->toBe($plaintext);
        expect($app->manual_webhook_secret_github)->toBe($plaintext);
    });
});
