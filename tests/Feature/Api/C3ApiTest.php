<?php

/**
 * Connect3 fork: /api/v1/projects/{uuid}/c3 endpoints (PRD 5.6, 9; acceptance 6 to 8).
 */

use App\Actions\Shared\CheckDomainDns;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const C3_API_APEX = 'sites.c3-staging.test';

beforeEach(function () {
    config(['app.env' => 'local', 'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
    Notification::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true, 'is_dns_validation_enabled' => true, 'c3_staging_apex' => C3_API_APEX],
    ));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = c3ApiToken($this->user, $this->team, ['*']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'ip' => '203.0.113.10']);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();

    $this->project = Project::create(['name' => 'Todd Plumbing', 'team_id' => $this->team->id, 'client_slug' => 'todd-plumbing']);
    $this->application = Application::factory()->create([
        'environment_id' => $this->project->environments()->first()->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://todd-plumbing.'.C3_API_APEX,
    ]);
});

function c3ApiToken(User $user, Team $team, array $abilities): string
{
    $plain = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'c3-api-test-'.Str::random(6),
        'token' => hash('sha256', $plain),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plain;
}

function c3ApiHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'];
}

function c3DnsResult(string $status, array $hosts): array
{
    return collect($hosts)->mapWithKeys(fn ($h) => [$h => [
        'status' => $status,
        'message' => $status === 'ok' ? 'DNS points to 203.0.113.10 (or Cloudflare).' : 'Domain does not point to 203.0.113.10.',
        'expected_ip' => '203.0.113.10',
        'checked_at' => now()->toIso8601String(),
    ]])->all();
}

describe('GET /projects/{uuid}/c3', function () {
    test('returns state, staging url and credentials for a root token', function () {
        $response = $this->withHeaders(c3ApiHeaders($this->bearerToken))->getJson("/api/v1/projects/{$this->project->uuid}/c3");

        $response->assertOk()->assertJson([
            'client_slug' => 'todd-plumbing',
            'site_state' => 'staged',
            'staging_url' => 'https://todd-plumbing.'.C3_API_APEX,
            'staging_auth_user' => 'todd-plumbing',
            'primary_application_uuid' => $this->application->uuid,
            'live_domains' => [],
        ]);
        expect($response->json('staging_auth_pass'))->toBe($this->project->fresh()->staging_auth_pass);
    });

    test('hides the password from a plain read token', function () {
        $token = c3ApiToken($this->user, $this->team, ['read']);
        $response = $this->withHeaders(c3ApiHeaders($token))->getJson("/api/v1/projects/{$this->project->uuid}/c3");

        $response->assertOk()->assertJsonMissing(['staging_auth_pass']);
        expect($response->json())->not->toHaveKey('staging_auth_pass');
    });

    test('is scoped to the token team', function () {
        $otherTeam = Team::factory()->create();
        $otherProject = Project::create(['name' => 'Other', 'team_id' => $otherTeam->id, 'client_slug' => 'other']);

        $this->withHeaders(c3ApiHeaders($this->bearerToken))
            ->getJson("/api/v1/projects/{$otherProject->uuid}/c3")
            ->assertNotFound();
    });
});

describe('PATCH /projects/{uuid}/c3', function () {
    test('sets the slug and gateway key', function () {
        $project = Project::create(['name' => 'New', 'team_id' => $this->team->id]);

        $this->withHeaders(c3ApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/projects/{$project->uuid}/c3", ['client_slug' => 'new-client', 'ai_gateway_key_id' => 'key_123'])
            ->assertOk()
            ->assertJson(['client_slug' => 'new-client', 'ai_gateway_key_id' => 'key_123', 'staging_url' => 'https://new-client.'.C3_API_APEX]);
    });

    test('rejects invalid, duplicate and unknown fields', function () {
        $project = Project::create(['name' => 'New', 'team_id' => $this->team->id]);
        $headers = c3ApiHeaders($this->bearerToken);

        $this->withHeaders($headers)->patchJson("/api/v1/projects/{$project->uuid}/c3", ['client_slug' => 'Bad Slug'])->assertStatus(422);
        $this->withHeaders($headers)->patchJson("/api/v1/projects/{$project->uuid}/c3", ['client_slug' => 'todd-plumbing'])->assertStatus(422);
        $this->withHeaders($headers)->patchJson("/api/v1/projects/{$project->uuid}/c3", ['site_state' => 'live'])->assertStatus(422);
    });

    test('a read-only token cannot write', function () {
        $token = c3ApiToken($this->user, $this->team, ['read']);

        $this->withHeaders(c3ApiHeaders($token))
            ->patchJson("/api/v1/projects/{$this->project->uuid}/c3", ['client_slug' => 'x'])
            ->assertForbidden();
    });
});

describe('POST /projects/{uuid}/c3/promote', function () {
    test('is blocked when DNS does not point at the server and changes nothing (acceptance 6)', function () {
        CheckDomainDns::mock()->shouldReceive('handle')->once()->andReturn(c3DnsResult('failed', ['example.com']));

        $response = $this->withHeaders(c3ApiHeaders($this->bearerToken))
            ->postJson("/api/v1/projects/{$this->project->uuid}/c3/promote", ['domains' => 'example.com']);

        $response->assertStatus(422);
        expect($response->json('message'))->toContain('DNS pre-flight failed')
            ->and($response->json('dns')['example.com']['status'])->toBe('failed');

        $project = $this->project->fresh();
        expect($project->site_state)->toBe('staged')->and($project->live_domains)->toBeNull();
    });

    test('goes live when DNS points at the server (acceptance 7)', function () {
        CheckDomainDns::mock()->shouldReceive('handle')->once()->andReturn(c3DnsResult('ok', ['example.com', 'www.example.com']));

        $response = $this->withHeaders(c3ApiHeaders($this->bearerToken))
            ->postJson("/api/v1/projects/{$this->project->uuid}/c3/promote", ['domains' => ['https://Example.com/', 'www.example.com']]);

        $response->assertOk()->assertJson([
            'site_state' => 'live',
            'live_domains' => ['example.com', 'www.example.com'],
            'live_urls' => ['https://example.com', 'https://www.example.com'],
            'staging_url' => 'https://todd-plumbing.'.C3_API_APEX,
        ]);
        expect($this->project->fresh()->isLive())->toBeTrue();
    });

    test('refuses domains under the staging apex', function () {
        CheckDomainDns::mock()->shouldNotReceive('handle');

        $this->withHeaders(c3ApiHeaders($this->bearerToken))
            ->postJson("/api/v1/projects/{$this->project->uuid}/c3/promote", ['domains' => 'other.'.C3_API_APEX])
            ->assertStatus(422);
    });

    test('requires domains', function () {
        $this->withHeaders(c3ApiHeaders($this->bearerToken))
            ->postJson("/api/v1/projects/{$this->project->uuid}/c3/promote", ['domains' => ''])
            ->assertStatus(422);
        $this->withHeaders(c3ApiHeaders($this->bearerToken))
            ->postJson("/api/v1/projects/{$this->project->uuid}/c3/promote", [])
            ->assertStatus(400);
    });
});

describe('POST /projects/{uuid}/c3/demote', function () {
    test('returns to staged and keeps the domains for re-promotion', function () {
        $this->project->update(['site_state' => 'live', 'live_domains' => ['example.com']]);

        $this->withHeaders(c3ApiHeaders($this->bearerToken))
            ->postJson("/api/v1/projects/{$this->project->uuid}/c3/demote", ['reason' => 'non-payment'])
            ->assertOk()
            ->assertJson(['site_state' => 'staged', 'live_domains' => ['example.com'], 'live_urls' => []]);
    });
});

describe('POST /projects/{uuid}/c3/regenerate-credentials', function () {
    test('rotates the password', function () {
        $before = $this->project->staging_auth_pass;

        $response = $this->withHeaders(c3ApiHeaders($this->bearerToken))
            ->postJson("/api/v1/projects/{$this->project->uuid}/c3/regenerate-credentials");

        $response->assertOk();
        $after = $this->project->fresh()->staging_auth_pass;
        expect($after)->not->toBe($before)
            ->and(strlen($after))->toBeGreaterThanOrEqual(20)
            ->and($response->json('staging_auth_pass'))->toBe($after);
    });
});

test('POST /projects accepts client_slug', function () {
    $response = $this->withHeaders(c3ApiHeaders($this->bearerToken))
        ->postJson('/api/v1/projects', ['name' => 'Rogers HVAC', 'client_slug' => 'rogers-hvac']);

    $response->assertStatus(201);
    $project = Project::whereUuid($response->json('uuid'))->firstOrFail();
    expect($project->client_slug)->toBe('rogers-hvac')
        ->and($project->staging_auth_user)->toBe('rogers-hvac');
});
