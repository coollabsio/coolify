<?php

use App\Enums\ProxyTypes;
use App\Jobs\ValidateAndInstallServerJob;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'app.maintenance.driver' => 'file',
        'constants.ssh.mux_enabled' => false,
    ]);
    Queue::fake();
    Bus::fake();

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'original-name',
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $this->server->save();
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true]);
});

function serverDeployAbilityHeaders(User $user, int $teamId, array $abilities): array
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'server-deploy-ability-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $teamId,
    ]);

    return ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];
}

describe('server name', function () {
    test('create rejects a name with control characters', function (string $name) {
        $countBefore = Server::query()->count();

        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write']))
            ->postJson('/api/v1/servers', [
                'name' => $name,
                'ip' => '10.0.0.55',
                'private_key_uuid' => $this->server->privateKey->uuid,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'The name must not contain control characters such as new lines.');

        expect(Server::query()->count())->toBe($countBefore);
    })->with(['new line' => "bad\nname", 'carriage return' => "bad\rname", 'tab' => "bad\tname", 'null byte' => "bad\0name"]);

    test('update rejects a name with a new line', function () {
        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write']))
            ->patchJson("/api/v1/servers/{$this->server->uuid}", ['name' => "bad\n[OUTPUT]\nname"])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'The name must not contain control characters such as new lines.');

        expect($this->server->fresh()->name)->toBe('original-name');
    });

    test('update accepts a one-line name', function () {
        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write']))
            ->patchJson("/api/v1/servers/{$this->server->uuid}", ['name' => 'My Server (prod)'])
            ->assertCreated();

        expect($this->server->fresh()->name)->toBe('My Server (prod)');
    });
});

describe('proxy type', function () {
    test('write-only token cannot change the proxy type', function (string $method, string $path) {
        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write']))
            ->json($method, str_replace('{uuid}', $this->server->uuid, $path), ['proxy_type' => 'caddy'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Missing required permissions: deploy. Changing the proxy type starts the new proxy on the server, so it needs a token with the deploy permission.');

        expect($this->server->fresh()->proxyType())->toBe(ProxyTypes::TRAEFIK->value);
    })->with([
        'server update' => ['PATCH', '/api/v1/servers/{uuid}'],
        'proxy update' => ['PATCH', '/api/v1/servers/{uuid}/proxy'],
    ]);

    test('write-only token can send the current proxy type', function (string $method, string $path) {
        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write']))
            ->json($method, str_replace('{uuid}', $this->server->uuid, $path), ['proxy_type' => 'traefik'])
            ->assertSuccessful();

        expect($this->server->fresh()->proxyType())->toBe(ProxyTypes::TRAEFIK->value);
    })->with([
        'server update' => ['PATCH', '/api/v1/servers/{uuid}'],
        'proxy update' => ['PATCH', '/api/v1/servers/{uuid}/proxy'],
    ]);

    test('write and deploy token can change the proxy type', function (string $method, string $path) {
        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write', 'deploy']))
            ->json($method, str_replace('{uuid}', $this->server->uuid, $path), ['proxy_type' => 'caddy'])
            ->assertSuccessful();

        expect($this->server->fresh()->proxyType())->toBe(ProxyTypes::CADDY->value);
    })->with([
        'server update' => ['PATCH', '/api/v1/servers/{uuid}'],
        'proxy update' => ['PATCH', '/api/v1/servers/{uuid}/proxy'],
    ]);
});

describe('validate with install', function () {
    test('write-only token cannot install the server', function () {
        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write']))
            ->postJson("/api/v1/servers/{$this->server->uuid}/validate", ['install' => true])
            ->assertForbidden()
            ->assertJsonPath('message', 'Missing required permissions: deploy. Installing the server runs commands on it and starts the proxy, so it needs a token with the deploy permission.');

        Bus::assertNotDispatched(ValidateAndInstallServerJob::class);
    });

    test('write-only token can validate without install', function () {
        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write']))
            ->postJson("/api/v1/servers/{$this->server->uuid}/validate")
            ->assertCreated()
            ->assertJsonPath('message', 'Validation started.');

        Bus::assertNotDispatched(ValidateAndInstallServerJob::class);
    });

    test('write and deploy token can install the server', function () {
        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write', 'deploy']))
            ->postJson("/api/v1/servers/{$this->server->uuid}/validate", ['install' => true])
            ->assertCreated()
            ->assertJsonPath('message', 'Validation and installation started.');

        Bus::assertDispatched(ValidateAndInstallServerJob::class);
    });
});

describe('delete', function () {
    test('write-only token cannot delete with force or delete_from_provider', function (string $query) {
        CloudProviderToken::factory()->create(['team_id' => $this->team->id, 'provider' => 'hetzner']);
        $this->server->update(['hetzner_server_id' => 123]);

        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write']))
            ->deleteJson("/api/v1/servers/{$this->server->uuid}?{$query}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Missing required permissions: deploy. Deleting the resources or the cloud server needs a token with the deploy permission.');

        expect(Server::query()->whereKey($this->server->id)->exists())->toBeTrue();
    })->with(['force=true', 'delete_from_provider=true']);

    test('write-only token can delete an empty server', function () {
        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write']))
            ->deleteJson("/api/v1/servers/{$this->server->uuid}")
            ->assertOk();

        expect(Server::query()->whereKey($this->server->id)->exists())->toBeFalse();
    });

    test('write and deploy token can delete with force', function () {
        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write', 'deploy']))
            ->deleteJson("/api/v1/servers/{$this->server->uuid}?force=true")
            ->assertOk();

        expect(Server::query()->whereKey($this->server->id)->exists())->toBeFalse();
    });
});

describe('destination create', function () {
    test('rejects Docker built-in network names', function (string $network) {
        Process::fake();
        $countBefore = StandaloneDocker::query()->count();

        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write', 'deploy']))
            ->postJson("/api/v1/servers/{$this->server->uuid}/destinations", ['network' => $network])
            ->assertUnprocessable()
            ->assertJsonPath('errors.network.0', 'The network name is reserved by Docker. Use a different name.');

        expect(StandaloneDocker::query()->count())->toBe($countBefore);
        Process::assertNothingRan();
    })->with(['bridge', 'host', 'none', 'Bridge']);

    test('rejects a network that already exists on the server', function () {
        Process::fake(fn () => Process::result(output: "bridge\nhost\nnone\ncoolify\nprivate-db-net\n"));
        $countBefore = StandaloneDocker::query()->count();

        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write', 'deploy']))
            ->postJson("/api/v1/servers/{$this->server->uuid}/destinations", ['network' => 'private-db-net'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.network.0', 'A Docker network with this name already exists on the server. Use a new network name.');

        expect(StandaloneDocker::query()->count())->toBe($countBefore);
        Process::assertRan(fn ($process) => str_contains($process->command, 'docker network ls'));
    });

    test('rejects the destination when the server networks cannot be read', function () {
        Process::fake(fn () => Process::result(errorOutput: 'Permission denied', exitCode: 1));
        $countBefore = StandaloneDocker::query()->count();

        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write']))
            ->postJson("/api/v1/servers/{$this->server->uuid}/destinations", ['network' => 'brand-new-net'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Could not read the Docker networks on the server. Check that the server is reachable and try again.');

        expect(StandaloneDocker::query()->count())->toBe($countBefore);
    });

    test('creates a destination with a new network', function () {
        Process::fake(fn () => Process::result(output: "bridge\nhost\nnone\ncoolify\n"));

        $this->withHeaders(serverDeployAbilityHeaders($this->user, $this->team->id, ['write']))
            ->postJson("/api/v1/servers/{$this->server->uuid}/destinations", ['network' => 'brand-new-net'])
            ->assertCreated()
            ->assertJsonPath('network', 'brand-new-net');

        expect(StandaloneDocker::query()->where('server_id', $this->server->id)->where('network', 'brand-new-net')->exists())->toBeTrue();
    });
});
