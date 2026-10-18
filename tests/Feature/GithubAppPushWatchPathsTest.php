<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

const PUSH_WATCH_PATHS_BEFORE_SHA = '1111111111111111111111111111111111111111';
const PUSH_WATCH_PATHS_AFTER_SHA = '2222222222222222222222222222222222222222';
const PUSH_WATCH_PATHS_COMPARE_URL = 'https://api.github.com/repos/test-org/test-repo/compare/'.PUSH_WATCH_PATHS_BEFORE_SHA.'...'.PUSH_WATCH_PATHS_AFTER_SHA;

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();

    $team = Team::factory()->create();

    openssl_pkey_export(openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]), $pemKey);

    $this->githubApp = GithubApp::create([
        'name' => 'github-app-push-watch-paths',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 1234567890,
        'installation_id' => 67890,
        'webhook_secret' => 'test-secret',
        'private_key_id' => PrivateKey::create([
            'name' => 'GitHub App Key',
            'private_key' => $pemKey,
            'team_id' => $team->id,
        ])->id,
        'is_public' => false,
        'team_id' => $team->id,
    ]);

    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $createApplication = fn (string $name, string $watchPaths): Application => Application::create([
        'name' => $name,
        'git_repository' => 'test-org/test-repo',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'watch_paths' => $watchPaths,
        'repository_project_id' => 987654321,
        'source_id' => $this->githubApp->id,
        'source_type' => GithubApp::class,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->payments = $createApplication('payments', 'packages/payments/**');
    $this->dashboard = $createApplication('dashboard', 'apps/dashboard/**');
});

/**
 * A push of a merge commit that names packages/payments/config.yml relative to
 * its first parent, while the net change of the push is only in apps/dashboard.
 */
function sendGithubAppMergePush(TestCase $test, string $before = PUSH_WATCH_PATHS_BEFORE_SHA): TestResponse
{
    $body = json_encode([
        'ref' => 'refs/heads/main',
        'before' => $before,
        'after' => PUSH_WATCH_PATHS_AFTER_SHA,
        'repository' => ['id' => 987654321, 'full_name' => 'test-org/test-repo'],
        'commits' => [
            ['message' => 'Merge integration into feature', 'added' => [], 'removed' => [], 'modified' => ['packages/payments/config.yml']],
            ['message' => 'Update dashboard', 'added' => [], 'removed' => [], 'modified' => ['apps/dashboard/page.tsx']],
        ],
    ], JSON_THROW_ON_ERROR);

    return $test->call('POST', '/webhooks/source/github/events', [], [], [], [
        'HTTP_X-GitHub-Event' => 'push',
        'HTTP_X-GitHub-Hook-Installation-Target-Id' => '1234567890',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

function fakeGithubAppPushApi(array $compareResponse): void
{
    Http::fake([
        'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, [
            'Date' => now()->toRfc7231String(),
        ]),
        'https://api.github.com/app/installations/67890/access_tokens' => Http::response([
            'token' => 'fake-installation-token',
        ], 201),
        PUSH_WATCH_PATHS_COMPARE_URL => $compareResponse === []
            ? Http::response(['message' => 'Server Error'], 500)
            : Http::response($compareResponse),
    ]);
}

function deployedApplicationIds(): array
{
    return ApplicationDeploymentQueue::query()->pluck('application_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
}

it('checks watch paths against the net files of the push, not the files of each commit', function () {
    fakeGithubAppPushApi(['status' => 'ahead', 'files' => [['filename' => 'apps/dashboard/page.tsx']]]);

    sendGithubAppMergePush($this)->assertOk();

    expect(deployedApplicationIds())->toBe([$this->dashboard->id]);
    Http::assertSent(fn (Request $request): bool => $request->url() === PUSH_WATCH_PATHS_COMPARE_URL);
});

it('triggers watch paths for the previous name of a renamed file', function () {
    fakeGithubAppPushApi(['status' => 'ahead', 'files' => [
        ['filename' => 'packages/legacy/config.yml', 'previous_filename' => 'packages/payments/config.yml', 'status' => 'renamed'],
    ]]);

    sendGithubAppMergePush($this)->assertOk();

    expect(deployedApplicationIds())->toBe([$this->payments->id]);
});

it('uses the files of the pushed commits when the compare API fails', function () {
    fakeGithubAppPushApi([]);

    sendGithubAppMergePush($this)->assertOk();

    expect(deployedApplicationIds())->toBe([$this->payments->id, $this->dashboard->id]);
});

it('uses the files of the pushed commits for a new branch', function () {
    fakeGithubAppPushApi(['status' => 'ahead', 'files' => []]);

    sendGithubAppMergePush($this, before: str_repeat('0', 40))->assertOk();

    expect(deployedApplicationIds())->toBe([$this->payments->id, $this->dashboard->id]);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/compare/'));
});

it('does not call the compare API when no application has watch paths', function () {
    Application::query()->update(['watch_paths' => null]);
    fakeGithubAppPushApi(['status' => 'ahead', 'files' => []]);

    sendGithubAppMergePush($this)->assertOk();

    expect(deployedApplicationIds())->toBe([$this->payments->id, $this->dashboard->id]);
    Http::assertNothingSent();
});
