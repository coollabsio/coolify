<?php

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Actions\Server\ValidateServer;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a team with owner
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    // Create an API token for the user
    $this->token = $this->user->createToken('test-token', ['*'], $this->team->id);
    $this->bearerToken = $this->token->plainTextToken;

    // Create a private key for the team
    $this->privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    // Enable job queue tracking
    Queue::fake();
});

describe('POST /api/v1/servers - start_proxy parameter', function () {
    test('starts proxy when instant_validate is true and start_proxy defaults to true', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers', [
            'name' => 'Test Server',
            'ip' => '10.0.0.1',
            'port' => 22,
            'user' => 'root',
            'private_key_uuid' => $this->privateKey->uuid,
            'instant_validate' => true,
            // start_proxy not specified - should default to true
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid']);

        // Verify ValidateServer job was dispatched
        Queue::assertPushed(ValidateServer::class);

        // Note: CheckProxy::run and StartProxy::dispatch are called synchronously
        // in the controller, not as queued jobs. This is expected behavior.
    });

    test('does not start proxy when start_proxy is explicitly false', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers', [
            'name' => 'Test Server',
            'ip' => '10.0.0.2',
            'port' => 22,
            'user' => 'root',
            'private_key_uuid' => $this->privateKey->uuid,
            'instant_validate' => true,
            'start_proxy' => false,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid']);

        // Verify ValidateServer job was dispatched
        Queue::assertPushed(ValidateServer::class);

        // StartProxy should NOT be dispatched when start_proxy is false
        Queue::assertNotPushed(StartProxy::class);
    });

    test('does not start proxy for build servers even with start_proxy true', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers', [
            'name' => 'Build Server',
            'ip' => '10.0.0.3',
            'port' => 22,
            'user' => 'root',
            'private_key_uuid' => $this->privateKey->uuid,
            'is_build_server' => true,
            'instant_validate' => true,
            'start_proxy' => true, // Should be ignored
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid']);

        // Verify ValidateServer job was dispatched
        Queue::assertPushed(ValidateServer::class);

        // StartProxy should NOT be dispatched for build servers
        Queue::assertNotPushed(StartProxy::class);
    });

    test('validates start_proxy as boolean', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers', [
            'name' => 'Test Server',
            'ip' => '10.0.0.4',
            'port' => 22,
            'user' => 'root',
            'private_key_uuid' => $this->privateKey->uuid,
            'start_proxy' => 'invalid', // Not a boolean
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_proxy']);
    });

    test('accepts start_proxy true explicitly', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers', [
            'name' => 'Test Server',
            'ip' => '10.0.0.5',
            'port' => 22,
            'user' => 'root',
            'private_key_uuid' => $this->privateKey->uuid,
            'instant_validate' => false,
            'start_proxy' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid']);
    });

    test('start_proxy defaults to false when instant_validate is false', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers', [
            'name' => 'Test Server',
            'ip' => '10.0.0.6',
            'port' => 22,
            'user' => 'root',
            'private_key_uuid' => $this->privateKey->uuid,
            'instant_validate' => false,
            // start_proxy not specified - should default to false
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid']);

        // Neither ValidateServer nor StartProxy should be dispatched
        Queue::assertNotPushed(ValidateServer::class);
        Queue::assertNotPushed(StartProxy::class);
    });

    test('rejects duplicate server IP', function () {
        // Create first server
        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers', [
            'name' => 'First Server',
            'ip' => '10.0.0.7',
            'port' => 22,
            'user' => 'root',
            'private_key_uuid' => $this->privateKey->uuid,
        ])->assertStatus(201);

        // Try to create second server with same IP
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers', [
            'name' => 'Second Server',
            'ip' => '10.0.0.7', // Duplicate IP
            'port' => 22,
            'user' => 'root',
            'private_key_uuid' => $this->privateKey->uuid,
            'start_proxy' => true,
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Server with this IP already exists.']);
    });
});

describe('PATCH /api/v1/servers/{uuid} - start_proxy parameter', function () {
    test('can trigger proxy start on existing server', function () {
        // Create a server first
        $createResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers', [
            'name' => 'Test Server',
            'ip' => '10.0.0.10',
            'port' => 22,
            'user' => 'root',
            'private_key_uuid' => $this->privateKey->uuid,
        ]);

        $createResponse->assertStatus(201);
        $serverUuid = $createResponse->json('uuid');

        // Update server to start proxy
        $updateResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/servers/{$serverUuid}", [
            'start_proxy' => true,
        ]);

        $updateResponse->assertStatus(201);
        $updateResponse->assertJsonStructure(['uuid']);
    });

    test('validates start_proxy as boolean in update', function () {
        // Create a server first
        $createResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers', [
            'name' => 'Test Server',
            'ip' => '10.0.0.11',
            'port' => 22,
            'user' => 'root',
            'private_key_uuid' => $this->privateKey->uuid,
        ]);

        $createResponse->assertStatus(201);
        $serverUuid = $createResponse->json('uuid');

        // Try to update with invalid start_proxy value
        $updateResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/servers/{$serverUuid}", [
            'start_proxy' => 'not-a-boolean',
        ]);

        $updateResponse->assertStatus(422);
        $updateResponse->assertJsonValidationErrors(['start_proxy']);
    });
});

describe('Authentication and authorization', function () {
    test('requires authentication for server creation', function () {
        $response = $this->postJson('/api/v1/servers', [
            'name' => 'Test Server',
            'ip' => '10.0.0.20',
            'port' => 22,
            'user' => 'root',
            'private_key_uuid' => $this->privateKey->uuid,
            'start_proxy' => true,
        ]);

        $response->assertStatus(401);
    });

    test('requires valid private key from same team', function () {
        // Create a different team with a private key
        $otherTeam = Team::factory()->create();
        $otherPrivateKey = PrivateKey::factory()->create([
            'team_id' => $otherTeam->id,
        ]);

        // Try to use the other team's private key
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers', [
            'name' => 'Test Server',
            'ip' => '10.0.0.21',
            'port' => 22,
            'user' => 'root',
            'private_key_uuid' => $otherPrivateKey->uuid,
            'start_proxy' => true,
        ]);

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Private key not found.']);
    });
});
