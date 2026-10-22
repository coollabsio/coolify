<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');
    config()->set('cache.default', 'array');

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->keyMaterial = generateSSHKey('ed25519')['private'];
    $this->teamA = Team::factory()->create();
    $this->teamB = Team::factory()->create();
});

function createTeamScopedPrivateKey(Team $team, string $material, string $name = 'key'): PrivateKey
{
    return PrivateKey::create([
        'name' => $name,
        'private_key' => $material,
        'team_id' => $team->id,
    ]);
}

test('the same key can be saved in two different teams', function () {
    $first = createTeamScopedPrivateKey($this->teamA, $this->keyMaterial);
    $second = createTeamScopedPrivateKey($this->teamB, $this->keyMaterial);

    expect($first->fingerprint)->toBe($second->fingerprint)
        ->and(PrivateKey::query()->where('fingerprint', $first->fingerprint)->count())->toBe(2);
});

test('a duplicate key in the same team is rejected', function () {
    createTeamScopedPrivateKey($this->teamA, $this->keyMaterial);

    expect(fn () => createTeamScopedPrivateKey($this->teamA, $this->keyMaterial, 'duplicate'))
        ->toThrow(ValidationException::class, 'This private key already exists.');
});

test('updating a key does not collide with itself', function () {
    $key = createTeamScopedPrivateKey($this->teamA, $this->keyMaterial);

    $key->update(['name' => 'renamed']);

    expect($key->fresh()->name)->toBe('renamed');
});

test('the root team (id 0) is scoped like any other team', function () {
    $rootTeam = Team::factory()->create(['id' => 0]);
    createTeamScopedPrivateKey($rootTeam, $this->keyMaterial);
    $fingerprint = PrivateKey::generateFingerprint($this->keyMaterial);

    expect(PrivateKey::fingerprintExists($fingerprint, teamId: 0))->toBeTrue()
        ->and(PrivateKey::fingerprintExists($fingerprint, teamId: $this->teamA->id))->toBeFalse()
        ->and(fn () => createTeamScopedPrivateKey($rootTeam, $this->keyMaterial, 'duplicate'))
        ->toThrow(ValidationException::class, 'This private key already exists.');

    createTeamScopedPrivateKey($this->teamA, $this->keyMaterial);
});

test('the saving hook checks the team of the key, not the team of the signed in user', function () {
    $user = User::factory()->create();
    $this->teamA->members()->attach($user->id, ['role' => 'owner']);
    $this->teamB->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->teamA]);

    createTeamScopedPrivateKey($this->teamA, $this->keyMaterial);

    // Another team may hold the same key while team A is the current team.
    createTeamScopedPrivateKey($this->teamB, $this->keyMaterial);

    // A duplicate in team B is rejected even though team A is the current team.
    expect(fn () => createTeamScopedPrivateKey($this->teamB, $this->keyMaterial, 'duplicate'))
        ->toThrow(ValidationException::class, 'This private key already exists.');
});

test('without a signed in user the check does not see keys of other teams', function () {
    expect(auth()->check())->toBeFalse();
    $existing = createTeamScopedPrivateKey($this->teamA, $this->keyMaterial);

    expect(PrivateKey::fingerprintExists($existing->fingerprint))->toBeFalse()
        ->and(PrivateKey::fingerprintExists($existing->fingerprint, teamId: $this->teamB->id))->toBeFalse()
        ->and(PrivateKey::fingerprintExists($existing->fingerprint, teamId: $this->teamA->id))->toBeTrue()
        ->and(PrivateKey::fingerprintExists($existing->fingerprint, $existing->id, $this->teamA->id))->toBeFalse();

    $copy = createTeamScopedPrivateKey($this->teamB, $this->keyMaterial);

    expect($copy->exists)->toBeTrue();
});

describe('POST /api/v1/security/keys', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->teamA->members()->attach($this->user->id, ['role' => 'owner']);
        $this->teamB->members()->attach($this->user->id, ['role' => 'owner']);

        session(['currentTeam' => $this->teamA]);
        $token = $this->user->createToken('write-token', ['write']);
        DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['team_id' => $this->teamA->id]);
        $this->bearerToken = $token->plainTextToken;
    });

    test('a key that exists in another team is created in the token team', function () {
        createTeamScopedPrivateKey($this->teamB, $this->keyMaterial);
        // The browser session points to team B; the API must use the token team.
        session(['currentTeam' => $this->teamB]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->bearerToken)
            ->postJson('/api/v1/security/keys', ['private_key' => $this->keyMaterial]);

        $response->assertCreated();
        expect(PrivateKey::query()->where('uuid', $response->json('uuid'))->value('team_id'))->toBe($this->teamA->id);
    });

    test('a key that exists in the token team is rejected', function () {
        createTeamScopedPrivateKey($this->teamA, $this->keyMaterial);
        session(['currentTeam' => $this->teamB]);

        $this->withHeader('Authorization', 'Bearer '.$this->bearerToken)
            ->postJson('/api/v1/security/keys', ['private_key' => $this->keyMaterial])
            ->assertStatus(422)
            ->assertJson(['message' => 'Private key already exists.']);

        expect(PrivateKey::query()->where('team_id', $this->teamA->id)->count())->toBe(1);
    });
});
