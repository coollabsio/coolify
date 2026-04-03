<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

describe('PATCH /api/v1/databases', function () {
    test('updates public_port_timeout on a postgresql database', function () {
        $database = StandalonePostgresql::create([
            'name' => 'test-postgres',
            'image' => 'postgres:15-alpine',
            'postgres_user' => 'postgres',
            'postgres_password' => 'password',
            'postgres_db' => 'postgres',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$database->uuid}", [
            'public_port_timeout' => 7200,
        ]);

        $response->assertStatus(200);
        $database->refresh();
        expect($database->public_port_timeout)->toBe(7200);
    });

    test('updates public_port_timeout on a redis database', function () {
        $database = StandaloneRedis::create([
            'name' => 'test-redis',
            'image' => 'redis:7',
            'redis_password' => 'password',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$database->uuid}", [
            'public_port_timeout' => 1800,
        ]);

        $response->assertStatus(200);
        $database->refresh();
        expect($database->public_port_timeout)->toBe(1800);
    });

    test('rejects invalid public_port_timeout value', function () {
        $database = StandalonePostgresql::create([
            'name' => 'test-postgres',
            'image' => 'postgres:15-alpine',
            'postgres_user' => 'postgres',
            'postgres_password' => 'password',
            'postgres_db' => 'postgres',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$database->uuid}", [
            'public_port_timeout' => 0,
        ]);

        $response->assertStatus(422);
    });

    test('accepts null public_port_timeout', function () {
        $database = StandalonePostgresql::create([
            'name' => 'test-postgres',
            'image' => 'postgres:15-alpine',
            'postgres_user' => 'postgres',
            'postgres_password' => 'password',
            'postgres_db' => 'postgres',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$database->uuid}", [
            'public_port_timeout' => null,
        ]);

        $response->assertStatus(200);
        $database->refresh();
        expect($database->public_port_timeout)->toBeNull();
    });
});

describe('POST /api/v1/databases/postgresql', function () {
    test('creates postgresql database with public_port_timeout', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/databases/postgresql', [
            'server_uuid' => $this->server->uuid,
            'project_uuid' => $this->project->uuid,
            'environment_name' => $this->environment->name,
            'public_port_timeout' => 5400,
            'instant_deploy' => false,
        ]);

        $response->assertStatus(200);
        $uuid = $response->json('uuid');
        $database = StandalonePostgresql::whereUuid($uuid)->first();
        expect($database)->not->toBeNull();
        expect($database->public_port_timeout)->toBe(5400);
    });
});
