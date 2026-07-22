<?php

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Actions\Proxy\StartProxy;
use App\Enums\ProxyTypes;
use App\Jobs\RestartProxyJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
        'app.maintenance.driver' => 'file',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = serverProxyApiToken($this->user, $this->team, ['*']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $this->server->proxy->set('status', 'exited');
    $this->server->proxy->redirect_enabled = true;
    $this->server->save();
});

function serverProxyApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function serverProxyApiToken(User $user, Team $team, array $abilities): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'server-proxy-api-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

test('GET /api/v1/servers/{uuid}/proxy returns proxy settings without configuration when none is stored', function () {
    $sensitiveToken = serverProxyApiToken($this->user, $this->team, ['read', 'read:sensitive']);

    $this->withHeaders(serverProxyApiHeaders($sensitiveToken))
        ->getJson("/api/v1/servers/{$this->server->uuid}/proxy")
        ->assertOk()
        ->assertJsonPath('proxy_type', ProxyTypes::TRAEFIK->value)
        ->assertJsonPath('redirect_enabled', true)
        ->assertJsonPath('redirect_url', null)
        ->assertJsonPath('generate_exact_labels', false)
        ->assertJsonPath('configuration', null);
});

test('GET /api/v1/servers/{uuid}/proxy omits stored configuration without read:sensitive', function () {
    $compose = "services:\n  traefik:\n    image: traefik:v3.5\n";
    $this->server->proxy->set('last_saved_proxy_configuration', $compose);
    $this->server->save();

    // '*' tokens grant all abilities including read:sensitive; use a read-only token.
    $readToken = serverProxyApiToken($this->user, $this->team, ['read']);

    $this->withHeaders(serverProxyApiHeaders($readToken))
        ->getJson("/api/v1/servers/{$this->server->uuid}/proxy")
        ->assertOk()
        ->assertJsonMissingPath('configuration');
});

test('GET /api/v1/servers/{uuid}/proxy returns stored configuration with read:sensitive for admins', function () {
    $compose = "services:\n  traefik:\n    image: traefik:v3.5\n";
    $this->server->proxy->set('last_saved_proxy_configuration', $compose);
    $this->server->save();

    $sensitiveToken = serverProxyApiToken($this->user, $this->team, ['read', 'read:sensitive']);

    $this->withHeaders(serverProxyApiHeaders($sensitiveToken))
        ->getJson("/api/v1/servers/{$this->server->uuid}/proxy")
        ->assertOk()
        ->assertJsonPath('configuration', $compose);
});

test('GET /api/v1/servers/{uuid}/proxy hides configuration from non-admin users with read:sensitive', function () {
    $compose = "services:\n  traefik:\n    image: traefik:v3.5\n";
    $this->server->proxy->set('last_saved_proxy_configuration', $compose);
    $this->server->save();

    // ApiSensitiveData requires admin/owner of the token team even with read:sensitive.
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    session(['currentTeam' => $this->team]);
    $memberToken = serverProxyApiToken($member, $this->team, ['read', 'read:sensitive']);

    // Members may be forbidden from viewing servers via policy; when allowed, config must still be hidden.
    $response = $this->withHeaders(serverProxyApiHeaders($memberToken))
        ->getJson("/api/v1/servers/{$this->server->uuid}/proxy");

    if ($response->status() === 200) {
        $response->assertJsonMissingPath('configuration');
    } else {
        $response->assertForbidden();
    }
});

test('GET /api/v1/servers/{uuid}/proxy does not expose another team server', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);

    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->getJson("/api/v1/servers/{$otherServer->uuid}/proxy")
        ->assertNotFound();
});

test('PATCH /api/v1/servers/{uuid}/proxy updates redirect and label settings without SSH', function () {
    // Factory servers are not reachable, so setupDefaultRedirect is skipped.
    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/servers/{$this->server->uuid}/proxy", [
            'redirect_enabled' => false,
            'redirect_url' => null,
            'generate_exact_labels' => true,
        ])
        ->assertOk()
        ->assertJsonPath('redirect_enabled', false)
        ->assertJsonPath('redirect_url', null)
        ->assertJsonPath('generate_exact_labels', true);

    $server = $this->server->fresh();

    expect((bool) data_get($server->proxy, 'redirect_enabled'))->toBeFalse()
        ->and(data_get($server->proxy, 'redirect_url'))->toBeNull()
        ->and((bool) $server->settings->generate_exact_labels)->toBeTrue();
});

test('PATCH /api/v1/servers/{uuid}/proxy rejects unknown fields', function () {
    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/servers/{$this->server->uuid}/proxy", [
            'redirect_enabled' => true,
            'unknown_field' => 'nope',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.unknown_field.0', 'This field is not allowed.');
});

test('PATCH /api/v1/servers/{uuid}/proxy rejects invalid proxy type', function () {
    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/servers/{$this->server->uuid}/proxy", [
            'proxy_type' => 'haproxy',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.proxy_type.0', 'Invalid proxy type.');
});

test('PATCH /api/v1/servers/{uuid}/proxy can change proxy type asynchronously', function () {
    StartProxy::shouldRun()->andReturn('OK');

    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/servers/{$this->server->uuid}/proxy", [
            'proxy_type' => 'caddy',
        ])
        ->assertOk()
        ->assertJsonPath('proxy_type', ProxyTypes::CADDY->value);

    expect($this->server->fresh()->proxyType())->toBe(ProxyTypes::CADDY->value);
});

test('PUT /api/v1/servers/{uuid}/proxy/configuration saves base64 configuration via action', function () {
    $compose = "services:\n  traefik:\n    image: traefik:v3.5\n";

    SaveProxyConfiguration::shouldRun()
        ->once()
        ->withArgs(function (Server $server, string $configuration) use ($compose) {
            return $server->is($this->server) && $configuration === $compose;
        });

    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->putJson("/api/v1/servers/{$this->server->uuid}/proxy/configuration", [
            'configuration' => base64_encode($compose),
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Proxy configuration saved.');
});

test('PUT /api/v1/servers/{uuid}/proxy/configuration rejects missing configuration', function () {
    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->putJson("/api/v1/servers/{$this->server->uuid}/proxy/configuration", [
            'foo' => 'bar',
        ])
        ->assertUnprocessable();
});

test('POST /api/v1/servers/{uuid}/proxy/restart queues RestartProxyJob', function () {
    Queue::fake();

    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->postJson("/api/v1/servers/{$this->server->uuid}/proxy/restart")
        ->assertOk()
        ->assertJsonPath('message', 'Proxy restart queued.');

    Queue::assertPushed(
        RestartProxyJob::class,
        fn (RestartProxyJob $job): bool => $job->server->is($this->server)
    );
});

test('proxy endpoints require authentication', function () {
    $this->getJson("/api/v1/servers/{$this->server->uuid}/proxy")->assertUnauthorized();
    $this->patchJson("/api/v1/servers/{$this->server->uuid}/proxy", ['redirect_enabled' => false])->assertUnauthorized();
    $this->putJson("/api/v1/servers/{$this->server->uuid}/proxy/configuration", ['configuration' => 'x'])->assertUnauthorized();
    $this->postJson("/api/v1/servers/{$this->server->uuid}/proxy/restart")->assertUnauthorized();
});
