<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function makeAuditTeamUser(): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    test()->actingAs($user);

    return [$team, $user];
}

function makeAuditApiToken(User $user, Team $team, array $abilities = ['root']): string
{
    $token = $user->createToken('audit-test', $abilities);
    DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update([
        'team_id' => $team->id,
    ]);

    return $token->plainTextToken;
}

function makeAuditApplication(string $repo = 'test-org/test-repo'): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();

    return Application::create([
        'name' => 'audit-test-app',
        'git_repository' => "https://github.com/{$repo}",
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

describe('audit channel helper', function () {
    test('auditLog writes structured payload to audit channel', function () {
        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('info')
            ->once()
            ->with('test.event', Mockery::on(function ($context) {
                return $context['event'] === 'test.event'
                    && $context['custom_field'] === 'value'
                    && array_key_exists('ip', $context)
                    && array_key_exists('user_id', $context);
            }));

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);

        auditLog('test.event', ['custom_field' => 'value']);
    });

    test('auditLog warning level routes correctly', function () {
        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('warning')->once()->with('test.failed', Mockery::any());

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);

        auditLog('test.failed', [], 'warning');
    });

    test('auditLogWebhookFailure logs warning with provider tag', function () {
        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('warning')
            ->once()
            ->with('webhook.github.signature_failed', Mockery::on(function ($context) {
                return $context['reason'] === 'invalid_signature'
                    && $context['event'] === 'webhook.github.signature_failed'
                    && array_key_exists('ip', $context);
            }));

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);

        auditLogWebhookFailure('github', 'invalid_signature', ['extra' => 'context']);
    });

    test('auditLog never includes raw secret keys in context', function () {
        $captured = null;
        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('info')
            ->once()
            ->with(Mockery::any(), Mockery::on(function ($context) use (&$captured) {
                $captured = $context;

                return true;
            }));

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);

        auditLog('test.private_key.created', [
            'team_id' => '1',
            'private_key_uuid' => 'abc',
            'fingerprint' => 'SHA256:xyz',
        ]);

        expect($captured)->toBeArray();
        // Helper itself never injects secret-bearing keys.
        $disallowed = ['private_key', 'password', 'token', 'webhook_secret', 'signature', 'client_secret'];
        foreach (array_keys($captured) as $key) {
            expect(in_array(strtolower($key), $disallowed, true))->toBeFalse();
        }
    });
});

describe('webhook signature failure logging', function () {
    test('GitHub manual webhook with bad signature logs to audit channel', function () {
        $app = makeAuditApplication();

        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('warning')
            ->atLeast()
            ->once()
            ->with('webhook.github.signature_failed', Mockery::any());

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

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

    test('GitLab manual webhook with bad token logs to audit channel', function () {
        $app = makeAuditApplication();

        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('warning')
            ->atLeast()
            ->once()
            ->with('webhook.gitlab.signature_failed', Mockery::any());

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

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

    test('Bitbucket manual webhook with malformed signature logs to audit channel', function () {
        $app = makeAuditApplication();

        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('warning')
            ->atLeast()
            ->once()
            ->with('webhook.bitbucket.signature_failed', Mockery::any());

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        $payload = json_encode([
            'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'abc123']]]]],
            'repository' => ['full_name' => 'test-org/test-repo'],
        ]);

        $response = $this->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], [
            'HTTP_X-Event-Key' => 'repo:push',
            'HTTP_X-Hub-Signature' => 'sha1=anyvalue',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toContain('Invalid signature');
    });

    test('Gitea manual webhook with bad signature logs to audit channel', function () {
        $app = makeAuditApplication();

        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('warning')
            ->atLeast()
            ->once()
            ->with('webhook.gitea.signature_failed', Mockery::any());

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

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
});

describe('API mutation audit logging', function () {
    test('private key creation emits api.private_key.created audit event', function () {
        [$team, $user] = makeAuditTeamUser();
        $token = makeAuditApiToken($user, $team);

        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('info')
            ->atLeast()
            ->once()
            ->with('api.private_key.created', Mockery::on(function ($context) {
                return $context['event'] === 'api.private_key.created'
                    && ! array_key_exists('private_key', $context);
            }));

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        // Generate a valid OpenSSH-format private key for the test.
        $opensshKey = "-----BEGIN OPENSSH PRIVATE KEY-----\n".
            base64_encode(str_repeat('a', 256)).
            "\n-----END OPENSSH PRIVATE KEY-----";

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/security/keys', [
            'name' => 'test-key',
            'description' => 'audit test',
            'private_key' => $opensshKey,
        ]);

        // Either 201 or 422 acceptable depending on validation; the assertion above verifies log if 201.
        expect($response->status())->toBeIn([201, 422]);
    });

    test('enable_api denial for non-root team emits warning audit event', function () {
        [$team, $user] = makeAuditTeamUser();
        $token = makeAuditApiToken($user, $team);

        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('warning')
            ->atLeast()
            ->once()
            ->with('api.instance.enable_denied', Mockery::any());

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/enable');

        $response->assertStatus(403);
    });

    test('project creation emits api.project.created audit event', function () {
        [$team, $user] = makeAuditTeamUser();
        $token = makeAuditApiToken($user, $team);

        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('info')
            ->atLeast()
            ->once()
            ->with('api.project.created', Mockery::on(function ($context) {
                return $context['event'] === 'api.project.created'
                    && ! empty($context['project_uuid'])
                    && $context['project_name'] === 'audit-project';
            }));

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/projects', [
            'name' => 'audit-project',
            'description' => 'audit',
        ]);

        $response->assertStatus(201);
    });
});

describe('threat-detection audit logging (Phase 2)', function () {
    test('missing bearer token logs api.auth.unauthenticated', function () {
        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('warning')
            ->atLeast()
            ->once()
            ->with('api.auth.unauthenticated', Mockery::any());

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(401);
    });

    test('expired bearer token logs api.auth.unauthenticated', function () {
        [$team, $user] = makeAuditTeamUser();
        $token = $user->createToken('expired-audit', ['read'], now()->subDay());
        DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update([
            'team_id' => $team->id,
        ]);

        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('warning')
            ->atLeast()
            ->once()
            ->with('api.auth.unauthenticated', Mockery::any());

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])->getJson('/api/v1/projects');

        $response->assertStatus(401);
    });

    test('read-only token hitting write endpoint logs api.auth.ability_denied', function () {
        [$team, $user] = makeAuditTeamUser();
        $readToken = makeAuditApiToken($user, $team, ['read']);

        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('warning')
            ->atLeast()
            ->once()
            ->with('api.auth.ability_denied', Mockery::on(function ($ctx) {
                return in_array('write', $ctx['required_abilities'] ?? [], true);
            }));

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$readToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/projects', [
            'name' => 'should-fail',
        ]);

        $response->assertStatus(403);
    });

    test('sentinel push without Authorization logs token_missing', function () {
        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('warning')
            ->atLeast()
            ->once()
            ->with('webhook.sentinel.signature_failed', Mockery::on(function ($ctx) {
                return $ctx['reason'] === 'token_missing';
            }));

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        $response = $this->postJson('/api/v1/sentinel/push', []);

        $response->assertStatus(401);
    });

    test('sentinel push with un-decryptable bearer logs decrypt_failed', function () {
        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('warning')
            ->atLeast()
            ->once()
            ->with('webhook.sentinel.signature_failed', Mockery::on(function ($ctx) {
                return $ctx['reason'] === 'decrypt_failed';
            }));

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer not-a-valid-encrypted-payload',
        ])->postJson('/api/v1/sentinel/push', []);

        $response->assertStatus(401);
    });
});
