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
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['is_api_enabled' => true]));

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
        $response->assertExactJson([]);
    });

    test('returns 404 for non-existent database', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/databases/non-existent-uuid/envs');

        $response->assertStatus(404);
        $response->assertExactJson(['message' => 'Database not found.']);
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
        $response->assertExactJson(['message' => 'Environment variable already exists. Use PATCH request to update it.']);

        $envs = EnvironmentVariable::where('resourceable_type', StandalonePostgresql::class)
            ->where('resourceable_id', $database->id)
            ->where('key', 'EXISTING_VAR')
            ->get();
        expect($envs)->toHaveCount(1)
            ->and($envs->first()->value)->toBe('existing_value');
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
        $response->assertJsonPath('message', 'Validation failed.');
        $response->assertJsonValidationErrors(['key']);

        expect(EnvironmentVariable::where('resourceable_type', StandalonePostgresql::class)
            ->where('resourceable_id', $database->id)
            ->count())->toBe(0);
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

    test('keeps value when only a flag is sent', function () {
        $database = createDatabase($this);

        $env = EnvironmentVariable::create([
            'key' => 'SECRET',
            'value' => 'super-secret',
            'resourceable_type' => StandalonePostgresql::class,
            'resourceable_id' => $database->id,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$database->uuid}/envs", [
            'key' => 'SECRET',
            'is_literal' => true,
        ])->assertStatus(201);

        $env->refresh();
        expect($env->value)->toBe('super-secret')
            ->and($env->is_literal)->toBeTruthy();
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
        $response->assertExactJson(['message' => 'Environment variable not found.']);

        expect(EnvironmentVariable::where('resourceable_type', StandalonePostgresql::class)
            ->where('resourceable_id', $database->id)
            ->count())->toBe(0);
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
        $response->assertJsonPath('message', 'Validation failed.');
        $response->assertJsonValidationErrors(['comment']);

        expect(EnvironmentVariable::where('resourceable_type', StandalonePostgresql::class)
            ->where('resourceable_id', $database->id)
            ->count())->toBe(0);
    });

    test('returns 400 when data is missing', function () {
        $database = createDatabase($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$database->uuid}/envs/bulk", []);

        $response->assertStatus(400);
        $response->assertExactJson(['message' => 'Bulk data is required.']);
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

        $env = EnvironmentVariable::create([
            'key' => 'KEEP_ME',
            'value' => 'kept',
            'resourceable_type' => StandalonePostgresql::class,
            'resourceable_id' => $database->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->deleteJson("/api/v1/databases/{$database->uuid}/envs/non-existent-uuid");

        $response->assertStatus(404);
        $response->assertExactJson(['message' => 'Environment variable not found.']);

        expect(EnvironmentVariable::where('uuid', $env->uuid)->exists())->toBeTrue();
    });
});

describe('environment variable key validation for database APIs', function () {
    test('rejects invalid database environment variable keys on create update and bulk', function () {
        $database = createDatabase($this);

        EnvironmentVariable::create([
            'key' => 'SAFE_KEY',
            'value' => 'old-value',
            'resourceable_type' => StandalonePostgresql::class,
            'resourceable_id' => $database->id,
        ]);

        $headers = [
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ];

        $this->withHeaders($headers)
            ->postJson("/api/v1/databases/{$database->uuid}/envs", [
                'key' => 'BAD$(id)',
                'value' => '1',
            ])
            ->assertStatus(422);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/databases/{$database->uuid}/envs", [
                'key' => 'BAD$(id)',
                'value' => '1',
            ])
            ->assertStatus(422);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/databases/{$database->uuid}/envs/bulk", [
                'data' => [[
                    'key' => 'BAD$(id)',
                    'value' => '1',
                ]],
            ])
            ->assertStatus(422);

        expect(EnvironmentVariable::query()
            ->where('resourceable_type', StandalonePostgresql::class)
            ->where('resourceable_id', $database->id)
            ->pluck('value', 'key')
            ->all())->toBe(['SAFE_KEY' => 'old-value']);
    });
});

describe('cross-team access to database environment variables', function () {
    test('a token of another team gets 404 and changes nothing', function () {
        $database = createDatabase($this);

        $env = EnvironmentVariable::create([
            'key' => 'TEAM_SECRET',
            'value' => 'original',
            'resourceable_type' => StandalonePostgresql::class,
            'resourceable_id' => $database->id,
        ]);

        $otherTeam = Team::factory()->create();
        $otherUser = User::factory()->create();
        $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);
        session(['currentTeam' => $otherTeam]);
        $otherToken = $otherUser->createToken('other-token', ['*'])->plainTextToken;

        $headers = [
            'Authorization' => 'Bearer '.$otherToken,
            'Content-Type' => 'application/json',
        ];
        $notFound = ['message' => 'Database not found.'];

        $this->withHeaders($headers)
            ->getJson("/api/v1/databases/{$database->uuid}/envs")
            ->assertStatus(404)
            ->assertExactJson($notFound);

        $this->withHeaders($headers)
            ->postJson("/api/v1/databases/{$database->uuid}/envs", ['key' => 'INJECTED', 'value' => 'x'])
            ->assertStatus(404)
            ->assertExactJson($notFound);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/databases/{$database->uuid}/envs", ['key' => 'TEAM_SECRET', 'value' => 'changed'])
            ->assertStatus(404)
            ->assertExactJson($notFound);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/databases/{$database->uuid}/envs/bulk", ['data' => [['key' => 'TEAM_SECRET', 'value' => 'changed']]])
            ->assertStatus(404)
            ->assertExactJson($notFound);

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/databases/{$database->uuid}/envs/{$env->uuid}")
            ->assertStatus(404)
            ->assertExactJson($notFound);

        $envs = EnvironmentVariable::where('resourceable_type', StandalonePostgresql::class)
            ->where('resourceable_id', $database->id)
            ->get();
        expect($envs)->toHaveCount(1)
            ->and($envs->first()->uuid)->toBe($env->uuid)
            ->and($envs->first()->value)->toBe('original');
    });
});
