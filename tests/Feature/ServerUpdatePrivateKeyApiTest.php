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

it('can disable build server mode via API', function () {
    $this->server->settings()->update(['is_build_server' => true]);

    patchServerUpdatePrivateKeyApi($this, $this->server, $this->bearerToken, [
        'is_build_server' => false,
    ])->assertCreated()
        ->assertJson(['uuid' => $this->server->uuid]);

    expect($this->server->settings->fresh()->is_build_server)->toBeFalse();
});

it('rejects an invalid disk usage check frequency without partially updating the server', function () {
    $this->server->proxy->set('type', 'TRAEFIK');
    $this->server->save();
    $this->server->settings()->update(['is_build_server' => false]);

    patchServerUpdatePrivateKeyApi($this, $this->server, $this->bearerToken, [
        'name' => 'Renamed Server',
        'is_build_server' => true,
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
        ->and($server->settings->is_build_server)->toBeFalse()
        ->and($server->proxy->get('type'))->toBe('TRAEFIK');
});
