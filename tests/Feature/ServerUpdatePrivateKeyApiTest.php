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

    $this->oldPrivateKey = createServerUpdatePrivateKeyApiKey($this->team, 'Old Key');
    $this->newPrivateKey = createServerUpdatePrivateKeyApiKey($this->team, 'New Key');

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->oldPrivateKey->id,
    ]);

    $token = $this->user->createToken('write-token', ['write']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $this->bearerToken = $token->plainTextToken;
});

function createServerUpdatePrivateKeyApiKey(Team $team, string $name): PrivateKey
{
    return PrivateKey::create([
        'name' => $name,
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $team->id,
    ]);
}

function patchServerUpdatePrivateKeyApi(object $test, Server $server, string $bearerToken, array $payload): TestResponse
{
    return $test->withHeaders([
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ])->patchJson('/api/v1/servers/'.$server->uuid, $payload);
}

function postServerApi(object $test, string $bearerToken, array $payload): TestResponse
{
    return $test->withHeaders([
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/servers', $payload);
}

it('updates the server private key from private_key_uuid', function () {
    patchServerUpdatePrivateKeyApi($this, $this->server, $this->bearerToken, [
        'private_key_uuid' => $this->newPrivateKey->uuid,
    ])->assertCreated()
        ->assertJson(['uuid' => $this->server->uuid]);

    expect($this->server->fresh()->private_key_id)->toBe($this->newPrivateKey->id);
});

it('returns not found for an unknown private_key_uuid and leaves the key unchanged', function () {
    patchServerUpdatePrivateKeyApi($this, $this->server, $this->bearerToken, [
        'private_key_uuid' => 'unknown-private-key-uuid',
    ])->assertNotFound()
        ->assertJson(['message' => 'Private key not found.']);

    expect($this->server->fresh()->private_key_id)->toBe($this->oldPrivateKey->id);
});

it('does not allow attaching a private key from another team', function () {
    $otherTeam = Team::factory()->create();
    $otherTeamPrivateKey = createServerUpdatePrivateKeyApiKey($otherTeam, 'Other Team Key');

    patchServerUpdatePrivateKeyApi($this, $this->server, $this->bearerToken, [
        'private_key_uuid' => $otherTeamPrivateKey->uuid,
    ])->assertNotFound()
        ->assertJson(['message' => 'Private key not found.']);

    expect($this->server->fresh()->private_key_id)->toBe($this->oldPrivateKey->id);
});

it('keeps the existing private key when private_key_uuid is omitted', function () {
    patchServerUpdatePrivateKeyApi($this, $this->server, $this->bearerToken, [
        'name' => 'Renamed Server',
    ])->assertCreated()
        ->assertJson(['uuid' => $this->server->uuid]);

    $server = $this->server->fresh();

    expect($server->name)->toBe('Renamed Server')
        ->and($server->private_key_id)->toBe($this->oldPrivateKey->id);
});

it('can change a build-only server to the combined role via API', function () {
    $this->server->settings()->update(['server_role' => 'build']);

    patchServerUpdatePrivateKeyApi($this, $this->server, $this->bearerToken, [
        'server_role' => 'both',
    ])->assertCreated()
        ->assertJson(['uuid' => $this->server->uuid]);

    $settings = $this->server->settings->fresh();

    expect($settings->server_role->value)->toBe('both');
});

it('updates the server role through the API', function (string $role) {
    patchServerUpdatePrivateKeyApi($this, $this->server, $this->bearerToken, [
        'server_role' => $role,
    ])->assertCreated();

    $settings = $this->server->settings->fresh();

    expect($settings->server_role->value)->toBe($role);
})->with([
    'build only' => ['build'],
    'deployment and build' => ['both'],
]);

it('rejects the legacy build server API field', function () {
    patchServerUpdatePrivateKeyApi($this, $this->server, $this->bearerToken, [
        'is_build_server' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('is_build_server');
});

it('requires another build-capable server before selecting deployment only', function () {
    patchServerUpdatePrivateKeyApi($this, $this->server, $this->bearerToken, [
        'server_role' => 'deployment',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('server_role');

    $buildServer = Server::factory()->create(['team_id' => $this->team->id]);
    $buildServer->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'server_role' => 'build',
        'is_build_server' => true,
    ]);

    patchServerUpdatePrivateKeyApi($this, $this->server, $this->bearerToken, [
        'server_role' => 'deployment',
    ])->assertCreated();

    expect($this->server->settings->fresh()->server_role->value)->toBe('deployment');
});

it('creates a server with an API server role', function () {
    $response = postServerApi($this, $this->bearerToken, [
        'name' => 'API Build Server',
        'ip' => '192.0.2.55',
        'private_key_uuid' => $this->oldPrivateKey->uuid,
        'server_role' => 'build',
    ])->assertCreated();

    $server = Server::whereUuid($response->json('uuid'))->firstOrFail();

    expect($server->settings->server_role->value)->toBe('build');
});

it('rejects an invalid API server role', function () {
    patchServerUpdatePrivateKeyApi($this, $this->server, $this->bearerToken, [
        'server_role' => 'invalid',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('server_role');
});

it('rejects an invalid disk usage check frequency without partially updating the server', function () {
    $this->server->proxy->set('type', 'TRAEFIK');
    $this->server->save();
    $this->server->settings()->update(['server_role' => 'both']);

    patchServerUpdatePrivateKeyApi($this, $this->server, $this->bearerToken, [
        'name' => 'Renamed Server',
        'server_role' => 'build',
        'proxy_type' => 'none',
        'server_disk_usage_check_frequency' => 'not a valid schedule',
    ])->assertUnprocessable()
        ->assertJson([
            'message' => 'Validation failed.',
            'errors' => [
                'server_disk_usage_check_frequency' => ['Invalid Cron / Human expression for Disk Usage Check Frequency.'],
            ],
        ]);

    $server = $this->server->fresh();

    expect($server->name)->not->toBe('Renamed Server')
        ->and($server->settings->server_role->value)->toBe('both')
        ->and($server->proxy->get('type'))->toBe('TRAEFIK');
});
