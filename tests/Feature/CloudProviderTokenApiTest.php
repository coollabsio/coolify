<?php

use App\Models\CloudProviderToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a team with owner
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    // Set the current team session before creating the token
    session(['currentTeam' => $this->team]);

    // Create an API token for the user
    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;
});

describe('GET /api/v1/cloud-tokens', function () {
    test('lists all cloud provider tokens for the team', function () {
        // Create some tokens
        CloudProviderToken::factory()->count(3)->create([
            'team_id' => $this->team->id,
            'provider' => 'hetzner',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/cloud-tokens');

        $response->assertStatus(200);
        $response->assertJsonCount(3);
        $response->assertJsonStructure([
            '*' => ['uuid', 'name', 'provider', 'team_id', 'servers_count', 'created_at', 'updated_at'],
        ]);
    });

    test('does not include tokens from other teams', function () {
        // Create tokens for this team
        CloudProviderToken::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'hetzner',
        ]);

        // Create tokens for another team
        $otherTeam = Team::factory()->create();
        CloudProviderToken::factory()->count(2)->create([
            'team_id' => $otherTeam->id,
            'provider' => 'hetzner',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/cloud-tokens');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
    });

    test('rejects request without authentication', function () {
        $response = $this->getJson('/api/v1/cloud-tokens');
        $response->assertStatus(401);
    });
});

describe('GET /api/v1/cloud-tokens/{uuid}', function () {
    test('gets cloud provider token by UUID', function () {
        $token = CloudProviderToken::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'hetzner',
            'name' => 'My Hetzner Token',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/cloud-tokens/{$token->uuid}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'My Hetzner Token', 'provider' => 'hetzner']);
    });

    test('returns 404 for non-existent token', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/cloud-tokens/non-existent-uuid');

        $response->assertStatus(404);
    });

    test('cannot access token from another team', function () {
        $otherTeam = Team::factory()->create();
        $token = CloudProviderToken::factory()->create([
            'team_id' => $otherTeam->id,
            'provider' => 'hetzner',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/cloud-tokens/{$token->uuid}");

        $response->assertStatus(404);
    });
});

describe('POST /api/v1/cloud-tokens', function () {
    test('creates a Hetzner cloud provider token', function () {
        // Mock Hetzner API validation
        Http::fake([
            'https://api.hetzner.cloud/v1/servers' => Http::response([], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/cloud-tokens', [
            'provider' => 'hetzner',
            'token' => 'test-hetzner-token',
            'name' => 'My Hetzner Token',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid']);

        // Verify token was created
        $this->assertDatabaseHas('cloud_provider_tokens', [
            'team_id' => $this->team->id,
            'provider' => 'hetzner',
            'name' => 'My Hetzner Token',
        ]);
    });

    test('creates a DigitalOcean cloud provider token', function () {
        // Mock DigitalOcean API validation
        Http::fake([
            'https://api.digitalocean.com/v2/account' => Http::response([], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/cloud-tokens', [
            'provider' => 'digitalocean',
            'token' => 'test-do-token',
            'name' => 'My DO Token',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid']);
    });

    test('validates provider is required', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/cloud-tokens', [
            'token' => 'test-token',
            'name' => 'My Token',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['provider']);
    });

    test('validates token is required', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/cloud-tokens', [
            'provider' => 'hetzner',
            'name' => 'My Token',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['token']);
    });

    test('validates name is required', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/cloud-tokens', [
            'provider' => 'hetzner',
            'token' => 'test-token',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    });

    test('validates provider must be hetzner or digitalocean', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/cloud-tokens', [
            'provider' => 'invalid-provider',
            'token' => 'test-token',
            'name' => 'My Token',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['provider']);
    });

    test('rejects invalid Hetzner token', function () {
        // Mock failed Hetzner API validation
        Http::fake([
            'https://api.hetzner.cloud/v1/servers' => Http::response([], 401),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/cloud-tokens', [
            'provider' => 'hetzner',
            'token' => 'invalid-token',
            'name' => 'My Token',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Invalid hetzner token. Please check your API token.']);
    });

    test('rejects extra fields not in allowed list', function () {
        Http::fake([
            'https://api.hetzner.cloud/v1/servers' => Http::response([], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/cloud-tokens', [
            'provider' => 'hetzner',
            'token' => 'test-token',
            'name' => 'My Token',
            'invalid_field' => 'invalid_value',
        ]);

        $response->assertStatus(422);
    });
});

describe('PATCH /api/v1/cloud-tokens/{uuid}', function () {
    test('updates cloud provider token name', function () {
        $token = CloudProviderToken::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'hetzner',
            'name' => 'Old Name',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/cloud-tokens/{$token->uuid}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200);

        // Verify token name was updated
        $this->assertDatabaseHas('cloud_provider_tokens', [
            'uuid' => $token->uuid,
            'name' => 'New Name',
        ]);
    });

    test('validates name is required', function () {
        $token = CloudProviderToken::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'hetzner',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/cloud-tokens/{$token->uuid}", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    });

    test('cannot update token from another team', function () {
        $otherTeam = Team::factory()->create();
        $token = CloudProviderToken::factory()->create([
            'team_id' => $otherTeam->id,
            'provider' => 'hetzner',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/cloud-tokens/{$token->uuid}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(404);
    });
});

describe('DELETE /api/v1/cloud-tokens/{uuid}', function () {
    test('deletes cloud provider token', function () {
        $token = CloudProviderToken::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'hetzner',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->deleteJson("/api/v1/cloud-tokens/{$token->uuid}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Cloud provider token deleted.']);

        // Verify token was deleted
        $this->assertDatabaseMissing('cloud_provider_tokens', [
            'uuid' => $token->uuid,
        ]);
    });

    test('cannot delete token from another team', function () {
        $otherTeam = Team::factory()->create();
        $token = CloudProviderToken::factory()->create([
            'team_id' => $otherTeam->id,
            'provider' => 'hetzner',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->deleteJson("/api/v1/cloud-tokens/{$token->uuid}");

        $response->assertStatus(404);
    });

    test('returns 404 for non-existent token', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->deleteJson('/api/v1/cloud-tokens/non-existent-uuid');

        $response->assertStatus(404);
    });
});

describe('POST /api/v1/cloud-tokens/{uuid}/validate', function () {
    test('validates a valid Hetzner token', function () {
        $token = CloudProviderToken::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'hetzner',
        ]);

        Http::fake([
            'https://api.hetzner.cloud/v1/servers' => Http::response([], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/cloud-tokens/{$token->uuid}/validate");

        $response->assertStatus(200);
        $response->assertJson(['valid' => true, 'message' => 'Token is valid.']);
    });

    test('detects invalid Hetzner token', function () {
        $token = CloudProviderToken::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'hetzner',
        ]);

        Http::fake([
            'https://api.hetzner.cloud/v1/servers' => Http::response([], 401),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/cloud-tokens/{$token->uuid}/validate");

        $response->assertStatus(200);
        $response->assertJson(['valid' => false, 'message' => 'Invalid hetzner token. Please check your API token.']);
    });

    test('validates a valid DigitalOcean token', function () {
        $token = CloudProviderToken::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'digitalocean',
        ]);

        Http::fake([
            'https://api.digitalocean.com/v2/account' => Http::response([], 200),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/cloud-tokens/{$token->uuid}/validate");

        $response->assertStatus(200);
        $response->assertJson(['valid' => true, 'message' => 'Token is valid.']);
    });
});
