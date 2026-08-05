<?php

use App\Actions\Proxy\DeleteProxyDynamicConfiguration;
use App\Actions\Proxy\ListProxyDynamicConfigurations;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Actions\Proxy\SaveProxyDynamicConfiguration;
use App\Actions\Proxy\StartProxy;
use App\Enums\ProxyTypes;
use App\Jobs\RestartProxyJob;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
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

test('GET dynamic configurations lists filenames without content when read:sensitive is absent', function () {
    $readToken = serverProxyApiToken($this->user, $this->team, ['read']);

    ListProxyDynamicConfigurations::shouldRun()
        ->once()
        ->withArgs(fn (Server $server, bool $includeContent): bool => $server->is($this->server) && ! $includeContent)
        ->andReturn([
            ['filename' => 'pos.yaml'],
        ]);

    $this->withHeaders(serverProxyApiHeaders($readToken))
        ->getJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations")
        ->assertOk()
        ->assertExactJson([
            ['filename' => 'pos.yaml'],
        ]);
});

test('GET dynamic configurations includes content with read:sensitive', function () {
    $content = "http:\n  routers: {}\n";
    $sensitiveToken = serverProxyApiToken($this->user, $this->team, ['read', 'read:sensitive']);

    ListProxyDynamicConfigurations::shouldRun()
        ->once()
        ->withArgs(fn (Server $server, bool $includeContent): bool => $server->is($this->server) && $includeContent)
        ->andReturn([
            ['filename' => 'pos.yaml', 'content' => $content],
        ]);

    $this->withHeaders(serverProxyApiHeaders($sensitiveToken))
        ->getJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations")
        ->assertOk()
        ->assertJsonPath('0.content', $content);
});

test('POST dynamic configurations creates a normalized Traefik file', function () {
    $content = "http:\n  routers: {}\n";

    SaveProxyDynamicConfiguration::shouldRun()
        ->once()
        ->withArgs(function (Server $server, string $filename, string $configuration, bool $create): bool {
            return $server->is($this->server)
                && $filename === 'pos.yaml'
                && $configuration === "http:\n  routers: {  }\n"
                && $create;
        })
        ->andReturnTrue();

    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->postJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations", [
            'filename' => 'pos',
            'content' => $content,
        ])
        ->assertCreated()
        ->assertJsonPath('message', 'Dynamic configuration created.')
        ->assertJsonPath('filename', 'pos.yaml');
});

test('POST dynamic configurations returns conflict when the file exists', function () {
    SaveProxyDynamicConfiguration::shouldRun()->once()->andReturnFalse();

    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->postJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations", [
            'filename' => 'pos.yaml',
            'content' => "http:\n  routers: {}\n",
        ])
        ->assertConflict()
        ->assertJsonPath('message', 'Dynamic configuration already exists. Use PATCH to update it.');
});

test('PATCH dynamic configurations updates an existing file', function () {
    SaveProxyDynamicConfiguration::shouldRun()
        ->once()
        ->withArgs(fn (Server $server, string $filename, string $configuration, bool $create): bool => $server->is($this->server)
            && $filename === 'pos.yaml'
            && filled($configuration)
            && ! $create)
        ->andReturnTrue();

    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations/pos.yaml", [
            'content' => "http:\n  routers: {}\n",
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Dynamic configuration updated.')
        ->assertJsonPath('filename', 'pos.yaml');
});

test('PATCH dynamic configurations returns not found when the file is absent', function () {
    SaveProxyDynamicConfiguration::shouldRun()->once()->andReturnFalse();

    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations/missing.yaml", [
            'content' => "http:\n  routers: {}\n",
        ])
        ->assertNotFound()
        ->assertJsonPath('message', 'Dynamic configuration not found.');
});

test('dynamic configuration writes reject invalid input before remote execution', function (array $payload, string $path) {
    SaveProxyDynamicConfiguration::shouldRun()->never();

    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->postJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations{$path}", $payload)
        ->assertUnprocessable();
})->with([
    'path traversal' => [['filename' => '../pos.yaml', 'content' => 'http: {}'], ''],
    'parent directory sequence' => [['filename' => 'pos..yaml', 'content' => 'http: {}'], ''],
    'wrong filename type' => [['filename' => ['pos.yaml'], 'content' => 'http: {}'], ''],
    'reserved normalized filename' => [['filename' => 'coolify', 'content' => 'http: {}'], ''],
    'unknown field' => [['filename' => 'pos.yaml', 'content' => 'http: {}', 'force' => true], ''],
    'wrong content type' => [['filename' => 'pos.yaml', 'content' => ['http']], ''],
    'oversized content' => [['filename' => 'pos.yaml', 'content' => str_repeat('a', 1_048_577)], ''],
]);

test('POST dynamic configurations rejects malformed Traefik YAML', function () {
    SaveProxyDynamicConfiguration::shouldRun()->never();

    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->postJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations", [
            'filename' => 'pos.yaml',
            'content' => "http:\n  routers: [",
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.content.0', 'The content must be valid YAML.');
});

test('POST dynamic configurations cannot overwrite the Coolify-managed Caddy file', function () {
    $this->server->proxy->set('type', ProxyTypes::CADDY->value);
    $this->server->save();

    SaveProxyDynamicConfiguration::shouldRun()->never();

    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->postJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations", [
            'filename' => 'coolify',
            'content' => 'example.com { reverse_proxy app:3000 }',
        ])
        ->assertUnprocessable();
});

test('POST dynamic configurations enforces the content limit in bytes', function () {
    SaveProxyDynamicConfiguration::shouldRun()->never();

    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->postJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations", [
            'filename' => 'pos.yaml',
            'content' => str_repeat('á', 524_289),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.content.0', 'The content must not exceed 1 MiB.');
});

test('DELETE dynamic configurations removes an existing file', function () {
    DeleteProxyDynamicConfiguration::shouldRun()
        ->once()
        ->withArgs(fn (Server $server, string $filename): bool => $server->is($this->server) && $filename === 'pos.yaml')
        ->andReturnTrue();

    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->deleteJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations/pos.yaml")
        ->assertOk()
        ->assertJsonPath('message', 'Dynamic configuration deleted.');
});

test('DELETE dynamic configurations rejects reserved files before remote execution', function () {
    DeleteProxyDynamicConfiguration::shouldRun()->never();

    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->deleteJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations/coolify.yaml")
        ->assertUnprocessable();
});

test('dynamic configuration endpoints do not expose another team server', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);

    ListProxyDynamicConfigurations::shouldRun()->never();

    $this->withHeaders(serverProxyApiHeaders($this->bearerToken))
        ->getJson("/api/v1/servers/{$otherServer->uuid}/proxy/dynamic-configurations")
        ->assertNotFound();
});

test('dynamic configuration actions use safe atomic remote commands', function () {
    $content = "http:\n  routers: {}\n";
    Process::fake([
        '*find *-exec basename*' => Process::result(output: "pos.yaml\n../unsafe.yaml\n"),
        '*wc -c*base64 <*' => Process::result(output: base64_encode($content)),
        '*' => Process::result(output: 'saved'),
    ]);

    expect(SaveProxyDynamicConfiguration::run($this->server, 'pos.yaml', $content, true))->toBeTrue()
        ->and(ListProxyDynamicConfigurations::run($this->server, true))->toBe([
            ['filename' => 'pos.yaml', 'content' => $content],
        ]);

    Process::assertRan(fn ($process): bool => str_contains($process->command, 'ln ')
        && str_contains($process->command, base64_encode($content))
        && ! str_contains($process->command, $content));
});

test('listing omits oversized file content while retaining the filename', function () {
    Process::fake([
        '*find *-exec basename*' => Process::result(output: "large.yaml\n"),
        '*wc -c*' => Process::result(output: '__COOLIFY_DYNAMIC_CONFIG_TOO_LARGE__'),
    ]);

    expect(ListProxyDynamicConfigurations::run($this->server, true))->toBe([
        ['filename' => 'large.yaml'],
    ]);
});

test('dynamic configuration actions report retry-safe conflicts and missing files', function () {
    Process::fake([
        '*ln *' => Process::result(output: 'exists'),
        '*test -f*rm -f*' => Process::result(output: 'missing'),
    ]);

    expect(SaveProxyDynamicConfiguration::run($this->server, 'pos.yaml', 'http: {}', true))->toBeFalse()
        ->and(DeleteProxyDynamicConfiguration::run($this->server, 'pos.yaml'))->toBeFalse();
});

test('proxy endpoints require authentication', function () {
    $this->getJson("/api/v1/servers/{$this->server->uuid}/proxy")->assertUnauthorized();
    $this->patchJson("/api/v1/servers/{$this->server->uuid}/proxy", ['redirect_enabled' => false])->assertUnauthorized();
    $this->putJson("/api/v1/servers/{$this->server->uuid}/proxy/configuration", ['configuration' => 'x'])->assertUnauthorized();
    $this->postJson("/api/v1/servers/{$this->server->uuid}/proxy/restart")->assertUnauthorized();
    $this->getJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations")->assertUnauthorized();
    $this->postJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations")->assertUnauthorized();
    $this->patchJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations/pos.yaml")->assertUnauthorized();
    $this->deleteJson("/api/v1/servers/{$this->server->uuid}/proxy/dynamic-configurations/pos.yaml")->assertUnauthorized();
});
