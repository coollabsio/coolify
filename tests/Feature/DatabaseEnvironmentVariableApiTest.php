<?php

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
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

function createDatabase($context): StandalonePostgresql
{
    return StandalonePostgresql::create([
        'name' => 'test-postgres',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $context->environment->id,
        'destination_id' => $context->destination->id,
        'destination_type' => $context->destination->getMorphClass(),
    ]);
}

describe('GET /api/v1/databases/{uuid}/envs', function () {
    test('lists environment variables for a database', function () {
        $database = createDatabase($this);

        EnvironmentVariable::create([
            'key' => 'CUSTOM_VAR',
            'value' => 'custom_value',
            'resourceable_type' => StandalonePostgresql::class,
            'resourceable_id' => $database->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/databases/{$database->uuid}/envs");

        $response->assertStatus(200);
        $response->assertJsonFragment(['key' => 'CUSTOM_VAR']);
    });

    test('returns empty array when no environment variables exist', function () {
        $database = createDatabase($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/databases/{$database->uuid}/envs");

        $response->assertStatus(200);
        $response->assertJson([]);
    });

    test('returns 404 for non-existent database', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/databases/non-existent-uuid/envs');

        $response->assertStatus(404);
    });
});

describe('POST /api/v1/databases/{uuid}/envs', function () {
    test('creates an environment variable', function () {
        $database = createDatabase($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/databases/{$database->uuid}/envs", [
            'key' => 'NEW_VAR',
            'value' => 'new_value',
        ]);

        $response->assertStatus(201);

        $env = EnvironmentVariable::where('key', 'NEW_VAR')
            ->where('resourceable_id', $database->id)
            ->where('resourceable_type', StandalonePostgresql::class)
            ->first();

        expect($env)->not->toBeNull();
        expect($env->value)->toBe('new_value');
    });

    test('creates an environment variable with comment', function () {
        $database = createDatabase($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/databases/{$database->uuid}/envs", [
            'key' => 'COMMENTED_VAR',
            'value' => 'some_value',
            'comment' => 'This is a test comment',
        ]);

        $response->assertStatus(201);

        $env = EnvironmentVariable::where('key', 'COMMENTED_VAR')
            ->where('resourceable_id', $database->id)
            ->first();

        expect($env->comment)->toBe('This is a test comment');
    });

    test('returns 409 when environment variable already exists', function () {
        $database = createDatabase($this);

        EnvironmentVariable::create([
            'key' => 'EXISTING_VAR',
            'value' => 'existing_value',
            'resourceable_type' => StandalonePostgresql::class,
            'resourceable_id' => $database->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/databases/{$database->uuid}/envs", [
            'key' => 'EXISTING_VAR',
            'value' => 'new_value',
        ]);

        $response->assertStatus(409);
    });

    test('returns 422 when key is missing', function () {
        $database = createDatabase($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/databases/{$database->uuid}/envs", [
            'value' => 'some_value',
        ]);

        $response->assertStatus(422);
    });
});

describe('PATCH /api/v1/databases/{uuid}/envs', function () {
    test('updates an environment variable', function () {
        $database = createDatabase($this);

        EnvironmentVariable::create([
            'key' => 'UPDATE_ME',
            'value' => 'old_value',
            'resourceable_type' => StandalonePostgresql::class,
            'resourceable_id' => $database->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$database->uuid}/envs", [
            'key' => 'UPDATE_ME',
            'value' => 'new_value',
        ]);

        $response->assertStatus(201);

        $env = EnvironmentVariable::where('key', 'UPDATE_ME')
            ->where('resourceable_id', $database->id)
            ->first();

        expect($env->value)->toBe('new_value');
    });

    test('returns 404 when environment variable does not exist', function () {
        $database = createDatabase($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$database->uuid}/envs", [
            'key' => 'NONEXISTENT',
            'value' => 'value',
        ]);

        $response->assertStatus(404);
    });
});

describe('PATCH /api/v1/databases/{uuid}/envs/bulk', function () {
    test('creates environment variables with comments', function () {
        $database = createDatabase($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$database->uuid}/envs/bulk", [
            'data' => [
                [
                    'key' => 'DB_HOST',
                    'value' => 'localhost',
                    'comment' => 'Database host',
                ],
                [
                    'key' => 'DB_PORT',
                    'value' => '5432',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $envWithComment = EnvironmentVariable::where('key', 'DB_HOST')
            ->where('resourceable_id', $database->id)
            ->where('resourceable_type', StandalonePostgresql::class)
            ->first();

        $envWithoutComment = EnvironmentVariable::where('key', 'DB_PORT')
            ->where('resourceable_id', $database->id)
            ->where('resourceable_type', StandalonePostgresql::class)
            ->first();

        expect($envWithComment->comment)->toBe('Database host');
        expect($envWithoutComment->comment)->toBeNull();
    });

    test('updates existing environment variables via bulk', function () {
        $database = createDatabase($this);

        EnvironmentVariable::create([
            'key' => 'BULK_VAR',
            'value' => 'old_value',
            'comment' => 'Old comment',
            'resourceable_type' => StandalonePostgresql::class,
            'resourceable_id' => $database->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$database->uuid}/envs/bulk", [
            'data' => [
                [
                    'key' => 'BULK_VAR',
                    'value' => 'new_value',
                    'comment' => 'Updated comment',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $env = EnvironmentVariable::where('key', 'BULK_VAR')
            ->where('resourceable_id', $database->id)
            ->first();

        expect($env->value)->toBe('new_value');
        expect($env->comment)->toBe('Updated comment');
    });

    test('rejects comment exceeding 256 characters', function () {
        $database = createDatabase($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$database->uuid}/envs/bulk", [
            'data' => [
                [
                    'key' => 'TEST_VAR',
                    'value' => 'value',
                    'comment' => str_repeat('a', 257),
                ],
            ],
        ]);

        $response->assertStatus(422);
    });

    test('returns 400 when data is missing', function () {
        $database = createDatabase($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$database->uuid}/envs/bulk", []);

        $response->assertStatus(400);
    });
});

describe('DELETE /api/v1/databases/{uuid}/envs/{env_uuid}', function () {
    test('deletes an environment variable', function () {
        $database = createDatabase($this);

        $env = EnvironmentVariable::create([
            'key' => 'DELETE_ME',
            'value' => 'to_delete',
            'resourceable_type' => StandalonePostgresql::class,
            'resourceable_id' => $database->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->deleteJson("/api/v1/databases/{$database->uuid}/envs/{$env->uuid}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Environment variable deleted.']);

        expect(EnvironmentVariable::where('uuid', $env->uuid)->first())->toBeNull();
    });

    test('returns 404 for non-existent environment variable', function () {
        $database = createDatabase($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->deleteJson("/api/v1/databases/{$database->uuid}/envs/non-existent-uuid");

        $response->assertStatus(404);
    });
});
