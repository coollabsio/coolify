<?php

use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function githubTestResponse(int $status, array $body, array $headers = []): Response
{
    Http::fake(['https://api.github.com/*' => Http::response($body, $status, $headers)]);

    return Http::get('https://api.github.com/repos/coolify/test');
}

function createPrivateGithubAppApplication(object $test): TestResponse
{
    return $test->withHeaders(['Authorization' => 'Bearer '.$test->bearerToken])
        ->postJson('/api/v1/applications/private-github-app', [
            'project_uuid' => $test->project->uuid,
            'environment_uuid' => $test->environment->uuid,
            'server_uuid' => $test->server->uuid,
            'github_app_uuid' => $test->githubApp->uuid,
            'git_repository' => 'coolify/private-repo',
            'git_branch' => 'main',
            'build_pack' => 'dockerfile',
            'ports_exposes' => '3000',
            'autogenerate_domain' => false,
        ]);
}

describe('githubRateLimitMessage', function () {
    test('names the reset time of an exhausted primary rate limit', function () {
        $response = githubTestResponse(403, ['message' => 'API rate limit exceeded for installation ID 78061994.'], [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) Carbon::parse('2025-07-30 21:12:38', 'UTC')->timestamp,
            'X-GitHub-Request-Id' => 'ABC123',
        ]);

        expect(githubRateLimitMessage($response))->toBe(
            'GitHub API rate limit exceeded. Try again after 2025-07-30 21:12:38 UTC. '
            .'See https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api. GitHub request ID: ABC123.'
        );
    });

    test('uses Retry-After of a secondary rate limit', function () {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $response = githubTestResponse(429, ['message' => 'You have exceeded a secondary rate limit.'], [
            'X-RateLimit-Remaining' => '4000',
            'Retry-After' => '120',
        ]);

        expect(githubRateLimitMessage($response))->toContain('Try again after 2026-01-01 10:02:00 UTC.');
    });

    test('ignores a 403 that is not a rate limit', function () {
        $response = githubTestResponse(403, ['message' => 'Resource not accessible by integration'], [
            'X-RateLimit-Remaining' => '4999',
        ]);

        expect(githubRateLimitMessage($response))->toBeNull();
    });

    test('ignores a 404', function () {
        $response = githubTestResponse(404, ['message' => 'Not Found'], ['X-RateLimit-Remaining' => '0']);

        expect(githubRateLimitMessage($response))->toBeNull();
    });
});

describe('POST /api/v1/applications/private-github-app', function () {
    beforeEach(function () {
        Storage::fake('ssh-keys');
        InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

        $this->team = Team::factory()->create();
        $user = User::factory()->create();
        $this->team->members()->attach($user->id, ['role' => 'owner']);
        session(['currentTeam' => $this->team]);

        $this->bearerToken = $user->createToken('github-rate-limit-test', ['*'])->plainTextToken;
        $this->server = Server::factory()->create(['team_id' => $this->team->id]);
        $this->project = Project::factory()->create(['team_id' => $this->team->id]);
        $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKeyPem);
        $privateKey = PrivateKey::create([
            'name' => 'GitHub App Key',
            'private_key' => $privateKeyPem,
            'team_id' => $this->team->id,
        ]);
        $this->githubApp = GithubApp::create([
            'name' => 'Rate Limit GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'app_id' => 12345,
            'installation_id' => 67890,
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'webhook_secret' => 'webhook-secret',
            'private_key_id' => $privateKey->id,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
            'is_public' => false,
        ]);
    });

    test('reports an exhausted GitHub rate limit instead of a missing repository', function () {
        Http::fake([
            'https://api.github.com/app/installations/67890/access_tokens' => Http::response(['token' => 'installation-token'], 201),
            'https://api.github.com/repos/coolify/private-repo' => Http::response(
                ['message' => 'API rate limit exceeded for installation ID 67890.'],
                403,
                ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) Carbon::parse('2025-07-30 21:12:38', 'UTC')->timestamp],
            ),
        ]);

        createPrivateGithubAppApplication($this)
            ->assertStatus(429)
            ->assertJsonPath('message', 'GitHub API rate limit exceeded. Try again after 2025-07-30 21:12:38 UTC. See https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api.');

        Http::assertSentCount(2);
    });

    test('still reports a repository that the GitHub App cannot read', function () {
        Http::fake([
            'https://api.github.com/app/installations/67890/access_tokens' => Http::response(['token' => 'installation-token'], 201),
            'https://api.github.com/repos/coolify/private-repo' => Http::response(['message' => 'Not Found'], 404, ['X-RateLimit-Remaining' => '4999']),
        ]);

        createPrivateGithubAppApplication($this)
            ->assertNotFound()
            ->assertJsonPath('message', 'Repository not found or not accessible by the GitHub App.');
    });
});
