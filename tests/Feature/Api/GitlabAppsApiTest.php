<?php

use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');
    config()->set('cache.default', 'array');

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;
});

describe('GET /api/v1/gitlab-apps', function () {
    test('returns 401 when not authenticated', function () {
        $this->getJson('/api/v1/gitlab-apps')->assertStatus(401);
    });

    test('returns empty array when no gitlab apps exist', function () {
        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/gitlab-apps')
            ->assertSuccessful()
            ->assertJson([]);
    });

    test('returns team gitlab apps without secrets for read tokens', function () {
        GitlabApp::create([
            'name' => 'Team GitLab',
            'api_url' => 'https://gitlab.com/api/v4',
            'html_url' => 'https://gitlab.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'client_id' => 'client-id',
            'client_secret' => 'secret-should-be-hidden',
            'webhook_token' => 'webhook-should-be-hidden',
            'team_id' => $this->team->id,
            'is_system_wide' => false,
            'is_public' => false,
        ]);

        $readToken = $this->user->createToken('read-token', ['read'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$readToken,
        ])->getJson('/api/v1/gitlab-apps');

        $response->assertSuccessful()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Team GitLab']);

        expect($response->json('0'))->not->toHaveKey('client_secret')
            ->and($response->json('0'))->not->toHaveKey('webhook_token');
    });

    test('does not return system-wide gitlab app secrets owned by another team', function () {
        $otherTeam = Team::factory()->create();
        GitlabApp::create([
            'name' => 'Foreign System GitLab',
            'api_url' => 'https://gitlab.com/api/v4',
            'html_url' => 'https://gitlab.com',
            'client_id' => 'foreign-client-id',
            'client_secret' => 'foreign-client-secret',
            'webhook_token' => 'foreign-webhook-token',
            'access_token' => 'foreign-access-token',
            'refresh_token' => 'foreign-refresh-token',
            'team_id' => $otherTeam->id,
            'is_system_wide' => true,
        ]);

        session(['currentTeam' => $this->team]);
        $sensitiveToken = $this->user->createToken('sensitive-token', ['read', 'read:sensitive'])->plainTextToken;

        $response = $this->withToken($sensitiveToken)
            ->getJson('/api/v1/gitlab-apps')
            ->assertSuccessful()
            ->assertJsonFragment(['name' => 'Foreign System GitLab']);

        expect($response->json('0'))
            ->not->toHaveKey('client_secret')
            ->not->toHaveKey('webhook_token')
            ->not->toHaveKey('access_token')
            ->not->toHaveKey('refresh_token');
    });
});

describe('POST /api/v1/gitlab-apps', function () {
    test('creates a gitlab app with derived api url and generated webhook token', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->postJson('/api/v1/gitlab-apps', [
            'name' => 'Self-hosted GitLab',
            'html_url' => 'https://gitlab.com/',
            'group_name' => 'mygroup',
        ]);

        $response->assertCreated()
            ->assertJsonFragment([
                'name' => 'Self-hosted GitLab',
                'html_url' => 'https://gitlab.com',
                'api_url' => 'https://gitlab.com/api/v4',
                'group_name' => 'mygroup',
                'custom_user' => 'git',
                'custom_port' => 22,
            ]);

        $app = GitlabApp::where('name', 'Self-hosted GitLab')->first();
        expect($app)->not->toBeNull()
            ->and($app->team_id)->toBe($this->team->id)
            ->and($app->webhook_token)->not->toBeEmpty()
            ->and(strlen((string) $app->webhook_token))->toBe(32);
    });

    test('creates a fully configured gitlab oauth source', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->postJson('/api/v1/gitlab-apps', [
            'name' => 'Configured GitLab',
            'html_url' => 'https://gitlab.com',
            'client_id' => 'oauth-app-id',
            'client_secret' => 'oauth-app-secret',
            'webhook_token' => 'custom-webhook-token',
            'redirect_uri' => 'https://example.com/webhooks/source/gitlab/redirect',
        ]);

        $response->assertCreated()
            ->assertJsonFragment([
                'name' => 'Configured GitLab',
                'client_id' => 'oauth-app-id',
                'redirect_uri' => 'https://example.com/webhooks/source/gitlab/redirect',
            ]);

        $app = GitlabApp::where('name', 'Configured GitLab')->first();
        $app->makeVisible(['client_secret', 'webhook_token']);
        expect($app->client_secret)->toBe('oauth-app-secret')
            ->and($app->webhook_token)->toBe('custom-webhook-token');
    });

    test('rejects members without create permission', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        session(['currentTeam' => $this->team]);
        $memberToken = $member->createToken('member-token', ['write'])->plainTextToken;

        $this->withHeaders([
            'Authorization' => 'Bearer '.$memberToken,
        ])->postJson('/api/v1/gitlab-apps', [
            'name' => 'Forbidden GitLab',
            'html_url' => 'https://gitlab.com',
        ])->assertForbidden();
    });
});

describe('PATCH /api/v1/gitlab-apps/{id}', function () {
    test('updates gitlab app credentials', function () {
        $app = GitlabApp::create([
            'name' => 'Existing',
            'api_url' => 'https://gitlab.com/api/v4',
            'html_url' => 'https://gitlab.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
            'is_public' => false,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->patchJson("/api/v1/gitlab-apps/{$app->id}", [
            'client_id' => 'new-client-id',
            'group_name' => 'ops',
        ])->assertSuccessful()
            ->assertJsonPath('message', 'GitLab app updated successfully')
            ->assertJsonPath('data.client_id', 'new-client-id')
            ->assertJsonPath('data.group_name', 'ops');
    });
});

describe('DELETE /api/v1/gitlab-apps/{id}', function () {
    test('deletes unused gitlab app', function () {
        $app = GitlabApp::create([
            'name' => 'Delete me',
            'api_url' => 'https://gitlab.com/api/v4',
            'html_url' => 'https://gitlab.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
            'is_public' => false,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->deleteJson("/api/v1/gitlab-apps/{$app->id}")
            ->assertSuccessful()
            ->assertJsonPath('message', 'GitLab app deleted successfully');

        expect(GitlabApp::find($app->id))->toBeNull();
    });
});
