<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');
    config()->set('cache.default', 'array');

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::create([
        'name' => 'Legacy Key',
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $this->team->id,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    $this->writeToken = legacyBuildServerApiToken($this->user, $this->team, ['write']);
    $this->readToken = legacyBuildServerApiToken($this->user, $this->team, ['read']);
});

function legacyBuildServerApiToken(User $user, Team $team, array $abilities): string
{
    $token = $user->createToken('legacy-'.implode('-', $abilities), $abilities);
    $token->accessToken->forceFill(['team_id' => $team->id])->save();

    return $token->plainTextToken;
}

function legacyBuildServerApi(object $test, string $bearerToken): object
{
    return $test->withHeaders([
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ]);
}

function createLegacyBuildServer(object $test, array $payload): TestResponse
{
    return legacyBuildServerApi($test, $test->writeToken)->postJson('/api/v1/servers', array_merge([
        'name' => 'Legacy Server',
        'ip' => '192.0.2.77',
        'private_key_uuid' => $test->privateKey->uuid,
    ], $payload));
}

function updateLegacyBuildServer(object $test, Server $server, array $payload, ?string $token = null): TestResponse
{
    return legacyBuildServerApi($test, $token ?? $test->writeToken)->patchJson('/api/v1/servers/'.$server->uuid, $payload);
}

describe('create', function () {
    it('maps is_build_server to the server role', function (bool $isBuildServer, string $expectedRole) {
        $response = createLegacyBuildServer($this, ['is_build_server' => $isBuildServer])->assertCreated();

        $server = Server::whereUuid($response->json('uuid'))->firstOrFail();

        expect($server->settings->server_role->value)->toBe($expectedRole)
            ->and($server->settings->is_build_server)->toBe($isBuildServer);
    })->with([
        'build server' => [true, 'build'],
        'regular server' => [false, 'both'],
    ]);

    it('accepts is_build_server together with a matching server_role', function () {
        $response = createLegacyBuildServer($this, [
            'is_build_server' => true,
            'server_role' => 'build',
        ])->assertCreated();

        expect(Server::whereUuid($response->json('uuid'))->firstOrFail()->settings->server_role->value)->toBe('build');
    });

    it('rejects is_build_server that conflicts with server_role', function (bool $isBuildServer, string $serverRole) {
        $serverCount = Server::count();

        createLegacyBuildServer($this, [
            'is_build_server' => $isBuildServer,
            'server_role' => $serverRole,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('is_build_server');

        expect(Server::count())->toBe($serverCount);
    })->with([
        'build flag with both role' => [true, 'both'],
        'build flag with deployment role' => [true, 'deployment'],
        'regular flag with build role' => [false, 'build'],
    ]);

    it('rejects a non-boolean is_build_server', function () {
        createLegacyBuildServer($this, ['is_build_server' => 'yes please'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_build_server');
    });
});

describe('update', function () {
    it('makes an empty server a build server with is_build_server true', function () {
        updateLegacyBuildServer($this, $this->server, ['is_build_server' => true])->assertCreated();

        $settings = $this->server->settings->fresh();

        expect($settings->server_role->value)->toBe('build')
            ->and($settings->is_build_server)->toBeTrue();
    });

    it('turns a build server back into a regular server with is_build_server false', function () {
        $this->server->settings()->update(['server_role' => 'build', 'is_build_server' => true]);

        updateLegacyBuildServer($this, $this->server, ['is_build_server' => false])->assertCreated();

        $settings = $this->server->settings->fresh();

        expect($settings->server_role->value)->toBe('both')
            ->and($settings->is_build_server)->toBeFalse();
    });

    it('keeps a deployment only server unchanged with is_build_server false', function () {
        $this->server->settings()->update(['server_role' => 'deployment', 'is_build_server' => false]);

        updateLegacyBuildServer($this, $this->server, ['is_build_server' => false])->assertCreated();

        expect($this->server->settings->fresh()->server_role->value)->toBe('deployment');
    });

    it('accepts is_build_server together with a matching server_role', function () {
        updateLegacyBuildServer($this, $this->server, [
            'is_build_server' => false,
            'server_role' => 'both',
        ])->assertCreated();

        expect($this->server->settings->fresh()->server_role->value)->toBe('both');
    });

    it('rejects is_build_server that conflicts with server_role without changing the server', function () {
        $this->server->settings()->update(['server_role' => 'both', 'is_build_server' => false]);

        updateLegacyBuildServer($this, $this->server, [
            'name' => 'Renamed Server',
            'is_build_server' => false,
            'server_role' => 'build',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('is_build_server');

        expect($this->server->fresh()->name)->not->toBe('Renamed Server')
            ->and($this->server->settings->fresh()->server_role->value)->toBe('both');
    });

    it('does not let a read-only token change the build server flag', function () {
        updateLegacyBuildServer($this, $this->server, ['is_build_server' => true], $this->readToken)
            ->assertForbidden();

        expect($this->server->settings->fresh()->server_role->value)->toBe('both');
    });

    it('does not update a server from another team', function () {
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);

        updateLegacyBuildServer($this, $otherServer, ['is_build_server' => true])->assertNotFound();

        expect($otherServer->settings->fresh()->server_role->value)->toBe('both');
    });
});

describe('read', function () {
    it('returns a derived is_build_server flag in the server settings', function (string $role, bool $expected) {
        $this->server->settings()->update(['server_role' => $role, 'is_build_server' => $role === 'build']);

        legacyBuildServerApi($this, $this->readToken)->getJson('/api/v1/servers/'.$this->server->uuid)
            ->assertOk()
            ->assertJsonPath('settings.server_role', $role)
            ->assertJsonPath('settings.is_build_server', $expected);

        $listed = collect(legacyBuildServerApi($this, $this->readToken)->getJson('/api/v1/servers')->assertOk()->json())
            ->firstWhere('uuid', $this->server->uuid);

        expect(data_get($listed, 'settings.is_build_server'))->toBe($expected);
    })->with([
        'build' => ['build', true],
        'both' => ['both', false],
        'deployment' => ['deployment', false],
    ]);

    it('derives is_build_server from the server role, not the stale legacy column', function () {
        $this->server->settings()->update(['server_role' => 'both', 'is_build_server' => true]);

        legacyBuildServerApi($this, $this->readToken)->getJson('/api/v1/servers/'.$this->server->uuid)
            ->assertOk()
            ->assertJsonPath('settings.is_build_server', false);
    });
});
