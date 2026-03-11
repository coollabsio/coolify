<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
});

describe('POST /api/v1/projects', function () {
    test('read-only token cannot create a project', function () {
        $token = $this->user->createToken('read-only', ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/projects', [
            'name' => 'Test Project',
        ]);

        $response->assertStatus(403);
    });

    test('write token can create a project', function () {
        $token = $this->user->createToken('write-token', ['write']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/projects', [
            'name' => 'Test Project',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid']);
    });

    test('root token can create a project', function () {
        $token = $this->user->createToken('root-token', ['root']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/projects', [
            'name' => 'Test Project',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid']);
    });
});

describe('POST /api/v1/servers', function () {
    test('read-only token cannot create a server', function () {
        $token = $this->user->createToken('read-only', ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers', [
            'name' => 'Test Server',
            'ip' => '1.2.3.4',
            'private_key_uuid' => 'fake-uuid',
        ]);

        $response->assertStatus(403);
    });
});
