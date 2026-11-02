<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('cache.default', 'array');

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->keyMaterial = generateSSHKey('ed25519')['private'];
    $this->key = PrivateKey::create(['name' => 'Old name', 'private_key' => $this->keyMaterial, 'team_id' => $this->team->id]);

    $token = $this->user->createToken('write-token', ['write']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $this->bearerToken = $token->plainTextToken;
});

function patchPrivateKeyUpdateApi(object $test, string $uuid, string $bearerToken, array $payload): TestResponse
{
    return $test->withHeaders(['Authorization' => 'Bearer '.$bearerToken])
        ->patchJson('/api/v1/security/keys/'.$uuid, $payload);
}

test('a private key can be renamed without sending the key again', function () {
    patchPrivateKeyUpdateApi($this, $this->key->uuid, $this->bearerToken, ['name' => 'New name', 'description' => 'Renamed'])
        ->assertCreated();

    $fresh = $this->key->fresh();
    expect($fresh->name)->toBe('New name')
        ->and($fresh->description)->toBe('Renamed')
        ->and($fresh->private_key)->toBe($this->key->private_key)
        ->and($fresh->fingerprint)->toBe($this->key->fingerprint);
});

test('a private key update still replaces and validates the key when it is sent', function () {
    $newKey = generateSSHKey('ed25519')['private'];

    patchPrivateKeyUpdateApi($this, $this->key->uuid, $this->bearerToken, ['private_key' => $newKey])->assertCreated();
    expect($this->key->fresh()->fingerprint)->toBe(PrivateKey::generateFingerprint($newKey));

    patchPrivateKeyUpdateApi($this, $this->key->uuid, $this->bearerToken, ['private_key' => 'not a key'])->assertUnprocessable();
    expect($this->key->fresh()->fingerprint)->toBe(PrivateKey::generateFingerprint($newKey));
});

test('a private key of another team cannot be renamed', function () {
    $otherTeam = Team::factory()->create();
    $foreign = PrivateKey::create(['name' => 'Theirs', 'private_key' => generateSSHKey('ed25519')['private'], 'team_id' => $otherTeam->id]);

    patchPrivateKeyUpdateApi($this, $foreign->uuid, $this->bearerToken, ['name' => 'Hijacked'])->assertNotFound();
    expect($foreign->fresh()->name)->toBe('Theirs');
});
