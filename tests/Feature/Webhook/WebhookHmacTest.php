<?php

use App\Http\Controllers\Webhook\Concerns\MatchesManualWebhookApplications;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Tests below change server settings; the static Server identity map must
    // not carry a cached server (same id after rollback) into the next test.
    Server::flushIdentityMap();
});

test('manual webhook routes are not rate limited per request', function (string $provider) {
    $route = Route::getRoutes()->match(Request::create("/webhooks/source/{$provider}/events/manual", 'POST'));

    expect(collect($route->gatherMiddleware())->filter(fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle')))->toBeEmpty();
})->with(['github', 'gitlab', 'bitbucket', 'gitea']);

/**
 * An invalid delivery uses a new random wrong secret unless $wrongSecret is set,
 * so each invalid delivery is a new guess (a new token or a new signature).
 */
function sendManualWebhookPush(TestCase $test, string $provider, Application $application, bool $validSignature = true, string $ip = '203.0.113.10', string $repository = 'test-org/test-repo', string $branch = 'main', ?string $wrongSecret = null, string $commit = 'abc123'): TestResponse
{
    $secret = $validSignature ? $application->{"manual_webhook_secret_{$provider}"} : ($wrongSecret ?? 'wrong-secret-'.Str::random(24));
    $server = ['REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/json'];

    if ($provider === 'gitlab') {
        $payload = json_encode([
            'object_kind' => 'push',
            'ref' => "refs/heads/{$branch}",
            'project' => ['path_with_namespace' => $repository],
            'after' => $commit,
            'commits' => [],
        ]);

        return $test->call('POST', '/webhooks/source/gitlab/events/manual', [], [], [], $server + [
            'HTTP_X-Gitlab-Token' => $secret,
        ], $payload);
    }

    if ($provider === 'bitbucket') {
        $payload = json_encode([
            'push' => ['changes' => [['new' => ['name' => $branch, 'target' => ['hash' => $commit]]]]],
            'repository' => ['full_name' => $repository],
        ]);

        return $test->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], $server + [
            'HTTP_X-Event-Key' => 'repo:push',
            'HTTP_X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $payload, $secret),
        ], $payload);
    }

    $payload = json_encode([
        'ref' => "refs/heads/{$branch}",
        'repository' => ['full_name' => $repository],
        'after' => $commit,
        'commits' => [],
    ]);
    $eventHeader = $provider === 'github' ? 'HTTP_X-GitHub-Event' : 'HTTP_X-Gitea-Event';

    return $test->call('POST', "/webhooks/source/{$provider}/events/manual", [], [], [], $server + [
        $eventHeader => 'push',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, $secret),
    ], $payload);
}

function manualWebhookFailureKey(string $provider, string $ip = '203.0.113.10', string $repository = 'test-org/test-repo', ?string $branch = 'main'): string
{
    $helper = new class
    {
        use MatchesManualWebhookApplications;

        public function key(Request $request, string $provider, string $repository, ?string $branch): string
        {
            return $this->manualWebhookFailureRateLimitKey($request, $provider, $this->manualWebhookRepositoryFullName($repository), $branch);
        }
    };

    return $helper->key(Request::create('/', 'POST', server: ['REMOTE_ADDR' => $ip]), $provider, $repository, $branch);
}

function lockOutManualWebhookRepository(TestCase $test, string $provider, Application $application, string $repository = 'test-org/test-repo', string $branch = 'main'): void
{
    for ($i = 0; $i < 30; $i++) {
        $response = sendManualWebhookPush($test, $provider, $application, validSignature: false, repository: $repository, branch: $branch);

        $response->assertOk();
        expect($response->getContent())->toContain('Invalid signature');
    }

    sendManualWebhookPush($test, $provider, $application, validSignature: false, repository: $repository, branch: $branch)
        ->assertStatus(429)
        ->assertHeader('Retry-After');
}

function makeWebhookApplicationServerFunctional(Application $application): Application
{
    $application->destination->server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);

    return $application->refresh();
}

describe('Manual Webhook Failed Authentication Rate Limiting', function () {
    test('valid signed deliveries are never throttled', function (string $provider) {
        $application = createApplicationWithWebhook();

        for ($i = 0; $i < 80; $i++) {
            $response = sendManualWebhookPush($this, $provider, $application);

            expect($response->getStatusCode())->toBe(200);
            expect($response->getContent())->not->toContain('Invalid signature');
        }

        expect(RateLimiter::attempts(manualWebhookFailureKey($provider)))->toBe(0);
    })->with(['github', 'gitlab', 'bitbucket', 'gitea']);

    test('repeated invalid signatures are throttled after 30 failures', function (string $provider) {
        $application = createApplicationWithWebhook();

        lockOutManualWebhookRepository($this, $provider, $application);

        expect(RateLimiter::tooManyAttempts(manualWebhookFailureKey($provider), 30))->toBeTrue();
    })->with(['github', 'gitlab', 'bitbucket', 'gitea']);

    test('a locked out repository rejects every delivery for that repository and branch before verification', function (string $provider) {
        Queue::fake();
        $application = makeWebhookApplicationServerFunctional(createApplicationWithWebhook());

        lockOutManualWebhookRepository($this, $provider, $application);

        sendManualWebhookPush($this, $provider, $application, validSignature: false)->assertStatus(429);
        // A correct guess during the lockout must not succeed, otherwise the
        // lockout does not limit how fast a secret can be guessed.
        sendManualWebhookPush($this, $provider, $application)->assertStatus(429);
        sendManualWebhookPush($this, $provider, $application, validSignature: false, repository: 'TEST-ORG/Test-Repo.git')->assertStatus(429);

        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
    })->with(['github', 'gitlab', 'bitbucket', 'gitea']);

    test('a wrong secret for one repository does not block a signed delivery for another repository from the same IP', function (string $provider) {
        Queue::fake();
        $misconfigured = createApplicationWithWebhook(repo: 'other-tenant/misconfigured-repo', overrides: ['name' => 'misconfigured-app']);
        $application = makeWebhookApplicationServerFunctional(createApplicationWithWebhook());

        lockOutManualWebhookRepository($this, $provider, $misconfigured, repository: 'other-tenant/misconfigured-repo');

        $response = sendManualWebhookPush($this, $provider, $application);

        $response->assertOk();
        expect($response->getContent())->toContain('Deployment queued');
        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeTrue();

        sendManualWebhookPush($this, $provider, $misconfigured, validSignature: false, repository: 'other-tenant/misconfigured-repo')->assertStatus(429);
    })->with(['github', 'gitlab', 'bitbucket', 'gitea']);

    test('deliveries for unknown repositories do not block signed deliveries for known repositories', function (string $provider) {
        Queue::fake();
        $application = makeWebhookApplicationServerFunctional(createApplicationWithWebhook());

        for ($i = 0; $i < 40; $i++) {
            $response = sendManualWebhookPush($this, $provider, $application, validSignature: false, repository: 'deleted-org/deleted-repo');

            expect($response->getStatusCode())->toBeIn([200, 429]);
        }

        $response = sendManualWebhookPush($this, $provider, $application);

        $response->assertOk();
        expect($response->getContent())->toContain('Deployment queued');
        expect(RateLimiter::attempts(manualWebhookFailureKey($provider)))->toBe(0);
    })->with(['github', 'gitlab', 'bitbucket', 'gitea']);

    test('pushes to untracked branches do not block the deployed branch', function (string $provider) {
        Queue::fake();
        $application = makeWebhookApplicationServerFunctional(createApplicationWithWebhook());

        for ($i = 0; $i < 40; $i++) {
            sendManualWebhookPush($this, $provider, $application, branch: 'feature/untracked');
        }

        $response = sendManualWebhookPush($this, $provider, $application);

        $response->assertOk();
        expect($response->getContent())->toContain('Deployment queued');
    })->with(['github', 'gitlab', 'bitbucket', 'gitea']);

    test('unknown repositories respond like invalid signatures and are still throttled', function () {
        $application = createApplicationWithWebhook();

        // Each delivery has a different payload. Identical redeliveries count once.
        for ($i = 0; $i < 30; $i++) {
            $response = sendManualWebhookPush($this, 'github', $application, repository: 'unknown-org/unknown-repo', commit: "commit-{$i}");

            $response->assertOk();
            expect($response->getContent())->toContain('Invalid signature');
        }

        sendManualWebhookPush($this, 'github', $application, repository: 'unknown-org/unknown-repo', commit: 'commit-30')->assertStatus(429);
    });

    test('valid deliveries do not count when another matching application has a different secret', function () {
        $application = createApplicationWithWebhook();
        createApplicationWithWebhook(overrides: ['name' => 'second-webhook-test-app']);

        for ($i = 0; $i < 35; $i++) {
            $response = sendManualWebhookPush($this, 'github', $application);

            $response->assertOk();
            expect($response->getContent())->not->toContain('Invalid signature');
        }

        expect(RateLimiter::attempts(manualWebhookFailureKey('github')))->toBe(0);
    });

    test('gitlab deliveries without a token are rejected and do not count as failed authentication', function () {
        createApplicationWithWebhook();

        for ($i = 0; $i < 40; $i++) {
            $response = $this->postJson('/webhooks/source/gitlab/events/manual', [
                'object_kind' => 'push',
                'ref' => 'refs/heads/main',
                'project' => ['path_with_namespace' => 'test-org/test-repo'],
            ]);

            $response->assertOk();
            expect($response->getContent())->toContain('Invalid signature');
        }

        expect(RateLimiter::attempts(manualWebhookFailureKey('gitlab', '127.0.0.1')))->toBe(0);
    });

    test('ping deliveries do not count as failures', function () {
        $application = createApplicationWithWebhook();

        for ($i = 0; $i < 40; $i++) {
            $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
                'REMOTE_ADDR' => '203.0.113.10',
                'HTTP_X-GitHub-Event' => 'ping',
                'CONTENT_TYPE' => 'application/json',
            ], '{}')->assertOk();
        }

        $response = sendManualWebhookPush($this, 'github', $application);

        $response->assertOk();
        expect($response->getContent())->not->toContain('Invalid signature');
    });

    test('failures on one provider do not block another provider', function () {
        $application = createApplicationWithWebhook();

        lockOutManualWebhookRepository($this, 'github', $application);

        foreach (['gitlab', 'bitbucket', 'gitea'] as $provider) {
            $response = sendManualWebhookPush($this, $provider, $application);

            $response->assertOk();
            expect($response->getContent())->not->toContain('Invalid signature');
        }
    });

    test('valid deliveries from a different client IP are not blocked', function () {
        $application = createApplicationWithWebhook();

        for ($i = 0; $i < 30; $i++) {
            sendManualWebhookPush($this, 'github', $application, validSignature: false, ip: '198.51.100.7');
        }
        sendManualWebhookPush($this, 'github', $application, validSignature: false, ip: '198.51.100.7')->assertStatus(429);

        $response = sendManualWebhookPush($this, 'github', $application, ip: '203.0.113.10');

        $response->assertOk();
        expect($response->getContent())->not->toContain('Invalid signature');
    });
});

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

describe('GitHub App Webhook HMAC', function () {
    test('rejects push when app webhook secret is empty', function () {
        $team = Team::factory()->create();
        GithubApp::create([
            'uuid' => (string) str()->uuid(),
            'name' => 'github-app-webhook-test',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'app_id' => 1234567890,
            'webhook_secret' => null,
            'team_id' => $team->id,
        ]);

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['id' => 987654321],
            'after' => 'abc123',
            'commits' => [],
        ]);

        $response = $this->call('POST', '/webhooks/source/github/events', [], [], [], [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-GitHub-Hook-Installation-Target-Id' => '1234567890',
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, ''),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('Invalid signature');
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
        expect($content)->toContain('Invalid signature.')
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
        expect($content)->toContain('Invalid signature.')
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

    test('github matches an ssh repository URL with a non-git username', function () {
        $app = createApplicationWithWebhook(overrides: [
            'git_repository' => 'custom-user@git.example.com:test-org/test-repo.git',
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

    test('github matches an ssh repository URL with a non-git username and custom port', function () {
        $app = createApplicationWithWebhook(overrides: [
            'git_repository' => 'custom-user@git.example.com:2222/test-org/test-repo.git',
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

/**
 * Send a manual webhook with any payload. The signature or token is valid for
 * $application. The payload is the raw body when it is a string.
 *
 * @param  array<string, mixed>|string  $payload
 * @param  array<string, string>  $server
 */
function sendSignedManualWebhook(TestCase $test, string $provider, Application $application, array|string $payload, array $server = []): TestResponse
{
    $body = is_string($payload) ? $payload : json_encode($payload);
    $secret = $application->{"manual_webhook_secret_{$provider}"};
    $signature = 'sha256='.hash_hmac('sha256', $body, $secret);
    $headers = match ($provider) {
        'gitlab' => ['HTTP_X-Gitlab-Token' => $secret],
        'bitbucket' => ['HTTP_X-Event-Key' => 'repo:push', 'HTTP_X-Hub-Signature' => $signature],
        'github' => ['HTTP_X-GitHub-Event' => 'push', 'HTTP_X-Hub-Signature-256' => $signature],
        'gitea' => ['HTTP_X-Gitea-Event' => 'push', 'HTTP_X-Hub-Signature-256' => $signature],
    };

    return $test->call('POST', "/webhooks/source/{$provider}/events/manual", [], [], [], $server + $headers + [
        'REMOTE_ADDR' => '203.0.113.10',
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

/**
 * A push payload for the provider, without a commit list.
 *
 * @return array<string, mixed>
 */
function manualWebhookPushPayloadWithoutCommits(string $provider, string $after = 'abc123'): array
{
    if ($provider === 'gitlab') {
        return [
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'project' => ['path_with_namespace' => 'test-org/test-repo'],
            'after' => $after,
        ];
    }

    return [
        'ref' => 'refs/heads/main',
        'repository' => ['full_name' => 'test-org/test-repo'],
        'after' => $after,
    ];
}

/**
 * Serialized content of the array cache store, used by the rate limiter in tests.
 */
function serializedWebhookCacheStore(): string
{
    $store = Cache::store()->getStore();
    $storage = (new ReflectionProperty($store, 'storage'))->getValue($store);

    return serialize($storage);
}

describe('Manual Webhook Push Payloads Without Commits', function () {
    test('a push without a commit list is deployed', function (string $provider, string $variant, ?string $watchPaths) {
        Queue::fake();
        $application = makeWebhookApplicationServerFunctional(createApplicationWithWebhook(overrides: ['watch_paths' => $watchPaths]));
        $payload = manualWebhookPushPayloadWithoutCommits($provider);
        if ($variant === 'null') {
            $payload['commits'] = null;
        }

        $response = sendSignedManualWebhook($this, $provider, $application, $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('Deployment queued');
        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeTrue();
    })->with(['github', 'gitlab', 'gitea'])
        ->with(['missing', 'null'])
        ->with(['no watch paths' => null, 'watch paths' => 'src/**']);

    test('a push with an empty commit list still honours watch paths', function (string $provider) {
        Queue::fake();
        $application = makeWebhookApplicationServerFunctional(createApplicationWithWebhook(overrides: ['watch_paths' => 'src/**']));
        $payload = manualWebhookPushPayloadWithoutCommits($provider) + ['commits' => []];

        $response = sendSignedManualWebhook($this, $provider, $application, $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('Changed files do not match watch paths');
        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
    })->with(['github', 'gitlab', 'gitea']);

    test('a push that deletes the branch does not queue a deployment', function (string $provider, ?string $watchPaths) {
        Queue::fake();
        $application = makeWebhookApplicationServerFunctional(createApplicationWithWebhook(overrides: ['watch_paths' => $watchPaths]));
        $payload = manualWebhookPushPayloadWithoutCommits($provider, after: str_repeat('0', 40));

        $response = sendSignedManualWebhook($this, $provider, $application, $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('Branch deleted');
        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
    })->with(['github', 'gitlab', 'gitea'])
        ->with(['no watch paths' => null, 'watch paths' => 'src/**']);

    test('a github push marked as deleted does not queue a deployment', function () {
        Queue::fake();
        $application = makeWebhookApplicationServerFunctional(createApplicationWithWebhook());
        $payload = manualWebhookPushPayloadWithoutCommits('github', after: 'abc123') + ['deleted' => true, 'commits' => []];

        $response = sendSignedManualWebhook($this, 'github', $application, $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('Branch deleted');
        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
    });
});

describe('Manual Webhook Malformed Payloads', function () {
    test('a malformed push payload gets a clean response', function (string $provider, array $payload, ?string $expected) {
        Queue::fake();
        $application = makeWebhookApplicationServerFunctional(createApplicationWithWebhook());

        $response = sendSignedManualWebhook($this, $provider, $application, $payload);

        $response->assertOk();
        if ($expected !== null) {
            expect($response->getContent())->toContain($expected);
        }
    })->with([
        'github without repository' => ['github', ['ref' => 'refs/heads/main', 'commits' => []], 'Invalid repository'],
        'github without ref' => ['github', ['repository' => ['full_name' => 'test-org/test-repo']], 'No branch'],
        'github with an array ref' => ['github', ['ref' => ['main'], 'repository' => ['full_name' => 'test-org/test-repo']], 'No branch'],
        'github with a string commit list' => ['github', ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'test-org/test-repo'], 'commits' => 'none'], 'Deployment queued'],
        'github with malformed commits' => ['github', ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'test-org/test-repo'], 'head_commit' => null, 'commits' => ['text', ['message' => ['x'], 'added' => [['nested']], 'modified' => 'README.md']]], 'Deployment queued'],
        'gitea without repository' => ['gitea', ['ref' => 'refs/heads/main'], 'Invalid repository'],
        'gitea without ref' => ['gitea', ['repository' => ['full_name' => 'test-org/test-repo']], 'No branch'],
        'gitea with an array ref' => ['gitea', ['ref' => ['main'], 'repository' => ['full_name' => 'test-org/test-repo']], 'No branch'],
        'gitea with malformed commits' => ['gitea', ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'test-org/test-repo'], 'commits' => [['message' => ['x'], 'removed' => [1, null]]]], 'Deployment queued'],
        'gitlab without project' => ['gitlab', ['object_kind' => 'push', 'ref' => 'refs/heads/main'], 'Invalid repository'],
        'gitlab without ref' => ['gitlab', ['object_kind' => 'push', 'project' => ['path_with_namespace' => 'test-org/test-repo']], 'No branch'],
        'gitlab with an array ref' => ['gitlab', ['object_kind' => 'push', 'ref' => ['main'], 'project' => ['path_with_namespace' => 'test-org/test-repo']], 'No branch'],
        'gitlab with a string commit list' => ['gitlab', ['object_kind' => 'push', 'ref' => 'refs/heads/main', 'project' => ['path_with_namespace' => 'test-org/test-repo'], 'commits' => 'none'], 'Deployment queued'],
        'gitlab with malformed commits' => ['gitlab', ['object_kind' => 'push', 'ref' => 'refs/heads/main', 'project' => ['path_with_namespace' => 'test-org/test-repo'], 'commits' => [['message' => ['x'], 'added' => ['a' => ['b']]]]], 'Deployment queued'],
        'gitlab merge request without attributes' => ['gitlab', ['object_kind' => 'merge_request', 'project' => ['path_with_namespace' => 'test-org/test-repo']], 'No branch'],
        'bitbucket without changes' => ['bitbucket', ['push' => [], 'repository' => ['full_name' => 'test-org/test-repo']], 'No branch'],
        'bitbucket with null changes' => ['bitbucket', ['push' => ['changes' => null], 'repository' => ['full_name' => 'test-org/test-repo']], 'No branch'],
        'bitbucket branch deletion' => ['bitbucket', ['push' => ['changes' => [['new' => null, 'old' => ['name' => 'main']]]], 'repository' => ['full_name' => 'test-org/test-repo']], 'No branch'],
        'bitbucket with an array branch' => ['bitbucket', ['push' => ['changes' => [['new' => ['name' => ['main']]]]], 'repository' => ['full_name' => 'test-org/test-repo']], 'No branch'],
        'bitbucket without repository' => ['bitbucket', ['push' => ['changes' => [['new' => ['name' => 'main']]]]], 'Invalid repository'],
        'bitbucket with malformed commits' => ['bitbucket', ['push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'abc123']], 'commits' => [['message' => ['x']], 'text']]]], 'repository' => ['full_name' => 'test-org/test-repo']], 'Deployment queued'],
    ]);

    test('gitea deliveries for unsupported events get a clean response', function () {
        $application = createApplicationWithWebhook();

        $response = sendSignedManualWebhook($this, 'gitea', $application, ['action' => 'opened'], ['HTTP_X-Gitea-Event' => 'issues']);

        $response->assertOk();
        expect($response->getContent())->toContain('not supported');
    });

    test('form encoded deliveries with a malformed payload field get a clean response', function (string $provider) {
        $application = createApplicationWithWebhook();
        $body = 'payload[]=x';

        $response = sendSignedManualWebhook($this, $provider, $application, $body, ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);

        $response->assertOk();
        expect($response->getContent())->toContain('No branch');
    })->with(['github', 'gitea']);
});

describe('App Webhook Push Payloads Without Commits', function () {
    test('github app push without a commit list is deployed', function () {
        Queue::fake();
        $team = Team::factory()->create();
        $githubApp = GithubApp::create([
            'uuid' => (string) str()->uuid(),
            'name' => 'github-app-commits-test',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'app_id' => 1234567891,
            'webhook_secret' => 'app-secret',
            'team_id' => $team->id,
            'is_public' => false,
        ]);
        $application = makeWebhookApplicationServerFunctional(createApplicationWithWebhook(overrides: [
            'source_id' => $githubApp->id,
            'source_type' => GithubApp::class,
            'repository_project_id' => 987654321,
            'watch_paths' => 'src/**',
        ]));
        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['id' => 987654321],
            'after' => 'abc123',
        ]);

        $response = $this->call('POST', '/webhooks/source/github/events', [], [], [], [
            'HTTP_X-GitHub-Event' => 'push',
            'HTTP_X-GitHub-Hook-Installation-Target-Id' => '1234567891',
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, 'app-secret'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeTrue();
    });

    test('gitlab app push without a commit list is deployed', function (string $variant) {
        Queue::fake();
        $team = Team::factory()->create();
        $gitlabApp = GitlabApp::create([
            'name' => 'gitlab-app-commits-test',
            'api_url' => 'https://gitlab.com/api/v4',
            'html_url' => 'https://gitlab.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'webhook_token' => 'gitlab-app-token',
            'team_id' => $team->id,
            'is_system_wide' => false,
            'is_public' => false,
        ]);
        $application = makeWebhookApplicationServerFunctional(createApplicationWithWebhook(overrides: [
            'source_id' => $gitlabApp->id,
            'source_type' => GitlabApp::class,
            'repository_project_id' => 4242,
            'watch_paths' => 'src/**',
        ]));
        $payload = [
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'project' => ['id' => 4242],
            'after' => 'abc123',
        ];
        if ($variant === 'null') {
            $payload['commits'] = null;
        }

        $response = $this->postJson('/webhooks/source/gitlab/events', $payload, ['X-Gitlab-Token' => 'gitlab-app-token']);

        $response->assertOk();
        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeTrue();
    })->with(['missing', 'null']);
});

describe('Manual Webhook Repeated Failed Deliveries', function () {
    test('gitlab deliveries that repeat the same wrong token count once', function () {
        Queue::fake();
        $application = makeWebhookApplicationServerFunctional(createApplicationWithWebhook());

        for ($i = 0; $i < 40; $i++) {
            $response = sendManualWebhookPush($this, 'gitlab', $application, validSignature: false, wrongSecret: 'old-hook-token');

            $response->assertOk();
            expect($response->getContent())->toContain('Invalid signature');
        }

        expect(RateLimiter::attempts(manualWebhookFailureKey('gitlab')))->toBe(1);

        $response = sendManualWebhookPush($this, 'gitlab', $application);

        $response->assertOk();
        expect($response->getContent())->toContain('Deployment queued');
        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeTrue();
    });

    test('gitlab deliveries with distinct wrong tokens lock the scope after 30', function () {
        Queue::fake();
        $application = makeWebhookApplicationServerFunctional(createApplicationWithWebhook());

        for ($i = 0; $i < 30; $i++) {
            $response = sendManualWebhookPush($this, 'gitlab', $application, validSignature: false, wrongSecret: "guess-{$i}");

            $response->assertOk();
            expect($response->getContent())->toContain('Invalid signature');
        }

        sendManualWebhookPush($this, 'gitlab', $application, validSignature: false, wrongSecret: 'guess-30')->assertStatus(429);
        sendManualWebhookPush($this, 'gitlab', $application)->assertStatus(429);
        // A token that was already counted is also rejected during the lockout.
        sendManualWebhookPush($this, 'gitlab', $application, validSignature: false, wrongSecret: 'guess-0')->assertStatus(429);

        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
    });

    test('repeated tokens do not extend the guess limit', function () {
        $application = createApplicationWithWebhook();

        // Repeats of counted tokens between new guesses do not reset or skip the count.
        for ($i = 0; $i < 29; $i++) {
            sendManualWebhookPush($this, 'gitlab', $application, validSignature: false, wrongSecret: "guess-{$i}")->assertOk();
            sendManualWebhookPush($this, 'gitlab', $application, validSignature: false, wrongSecret: 'guess-0')->assertOk();
        }
        expect(RateLimiter::attempts(manualWebhookFailureKey('gitlab')))->toBe(29);

        sendManualWebhookPush($this, 'gitlab', $application, validSignature: false, wrongSecret: 'guess-29')->assertOk();
        sendManualWebhookPush($this, 'gitlab', $application, validSignature: false, wrongSecret: 'guess-30')->assertStatus(429);
    });

    test('failure tracking stores no raw gitlab token and stays bounded', function () {
        $application = createApplicationWithWebhook();
        $rawToken = 'raw-token-that-must-never-be-stored';

        sendManualWebhookPush($this, 'gitlab', $application, validSignature: false, wrongSecret: $rawToken);
        for ($i = 0; $i < 40; $i++) {
            sendManualWebhookPush($this, 'gitlab', $application, validSignature: false, wrongSecret: "{$rawToken}-{$i}");
        }

        $stored = serializedWebhookCacheStore();
        expect($stored)->not->toContain($rawToken);
        expect($stored)->not->toContain($application->manual_webhook_secret_gitlab);

        $seen = Cache::get(manualWebhookFailureKey('gitlab').':seen');
        expect($seen)->toBeArray();
        expect(count($seen))->toBeLessThanOrEqual(30);
        foreach ($seen as $marker) {
            expect($marker)->toMatch('/\A[0-9a-f]{64}\z/');
        }
    });

    test('a repeated wrong token counts again in a new failure window', function () {
        $application = createApplicationWithWebhook();

        sendManualWebhookPush($this, 'gitlab', $application, validSignature: false, wrongSecret: 'old-hook-token');
        sendManualWebhookPush($this, 'gitlab', $application, validSignature: false, wrongSecret: 'old-hook-token');
        expect(RateLimiter::attempts(manualWebhookFailureKey('gitlab')))->toBe(1);

        $this->travel(61)->seconds();

        sendManualWebhookPush($this, 'gitlab', $application, validSignature: false, wrongSecret: 'old-hook-token');
        expect(RateLimiter::attempts(manualWebhookFailureKey('gitlab')))->toBe(1);
    });

    test('identical redeliveries of a signed payload count once', function (string $provider) {
        Queue::fake();
        $application = makeWebhookApplicationServerFunctional(createApplicationWithWebhook());

        for ($i = 0; $i < 40; $i++) {
            $response = sendManualWebhookPush($this, $provider, $application, validSignature: false, wrongSecret: 'old-hook-secret');

            $response->assertOk();
            expect($response->getContent())->toContain('Invalid signature');
        }

        expect(RateLimiter::attempts(manualWebhookFailureKey($provider)))->toBe(1);

        $response = sendManualWebhookPush($this, $provider, $application);

        $response->assertOk();
        expect($response->getContent())->toContain('Deployment queued');
    })->with(['github', 'bitbucket', 'gitea']);

    test('different wrong signatures for the same payload still lock after 30', function (string $provider) {
        Queue::fake();
        $application = makeWebhookApplicationServerFunctional(createApplicationWithWebhook());

        for ($i = 0; $i < 30; $i++) {
            sendManualWebhookPush($this, $provider, $application, validSignature: false, wrongSecret: "guess-{$i}")->assertOk();
        }

        sendManualWebhookPush($this, $provider, $application, validSignature: false, wrongSecret: 'guess-30')->assertStatus(429);
        sendManualWebhookPush($this, $provider, $application)->assertStatus(429);
        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
    })->with(['github', 'bitbucket', 'gitea']);

    test('the same wrong signature for different payloads counts every payload', function (string $provider) {
        $application = createApplicationWithWebhook();

        for ($i = 0; $i < 30; $i++) {
            sendManualWebhookPush($this, $provider, $application, validSignature: false, wrongSecret: 'old-hook-secret', commit: "commit-{$i}")->assertOk();
        }

        sendManualWebhookPush($this, $provider, $application, validSignature: false, wrongSecret: 'old-hook-secret', commit: 'commit-30')->assertStatus(429);
    })->with(['github', 'bitbucket', 'gitea']);
});
