<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
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

describe('PATCH /api/v1/applications/{uuid}/envs/bulk', function () {
    test('creates environment variables with comments', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/applications/{$application->uuid}/envs/bulk", [
            'data' => [
                [
                    'key' => 'DB_HOST',
                    'value' => 'localhost',
                    'comment' => 'Database host for production',
                ],
                [
                    'key' => 'DB_PORT',
                    'value' => '5432',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $envWithComment = EnvironmentVariable::where('key', 'DB_HOST')
            ->where('resourceable_id', $application->id)
            ->where('is_preview', false)
            ->first();

        $envWithoutComment = EnvironmentVariable::where('key', 'DB_PORT')
            ->where('resourceable_id', $application->id)
            ->where('is_preview', false)
            ->first();

        expect($envWithComment->comment)->toBe('Database host for production');
        expect($envWithoutComment->comment)->toBeNull();
    });

    test('updates existing environment variable comment', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        EnvironmentVariable::create([
            'key' => 'API_KEY',
            'value' => 'old-key',
            'comment' => 'Old comment',
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
            'is_preview' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/applications/{$application->uuid}/envs/bulk", [
            'data' => [
                [
                    'key' => 'API_KEY',
                    'value' => 'new-key',
                    'comment' => 'Updated comment',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $env = EnvironmentVariable::where('key', 'API_KEY')
            ->where('resourceable_id', $application->id)
            ->where('is_preview', false)
            ->first();

        expect($env->value)->toBe('new-key');
        expect($env->comment)->toBe('Updated comment');
    });

    test('preserves existing comment when not provided in bulk update', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        EnvironmentVariable::create([
            'key' => 'SECRET',
            'value' => 'old-secret',
            'comment' => 'Keep this comment',
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
            'is_preview' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/applications/{$application->uuid}/envs/bulk", [
            'data' => [
                [
                    'key' => 'SECRET',
                    'value' => 'new-secret',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $env = EnvironmentVariable::where('key', 'SECRET')
            ->where('resourceable_id', $application->id)
            ->where('is_preview', false)
            ->first();

        expect($env->value)->toBe('new-secret');
        expect($env->comment)->toBe('Keep this comment');
    });

    test('rejects comment exceeding 256 characters', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/applications/{$application->uuid}/envs/bulk", [
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
});

describe('PATCH /api/v1/services/{uuid}/envs/bulk', function () {
    test('creates environment variables with comments', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/services/{$service->uuid}/envs/bulk", [
            'data' => [
                [
                    'key' => 'REDIS_HOST',
                    'value' => 'redis',
                    'comment' => 'Redis cache host',
                ],
                [
                    'key' => 'REDIS_PORT',
                    'value' => '6379',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $envWithComment = EnvironmentVariable::where('key', 'REDIS_HOST')
            ->where('resourceable_id', $service->id)
            ->where('resourceable_type', Service::class)
            ->first();

        $envWithoutComment = EnvironmentVariable::where('key', 'REDIS_PORT')
            ->where('resourceable_id', $service->id)
            ->where('resourceable_type', Service::class)
            ->first();

        expect($envWithComment->comment)->toBe('Redis cache host');
        expect($envWithoutComment->comment)->toBeNull();
    });

    test('rejects comment exceeding 256 characters', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/services/{$service->uuid}/envs/bulk", [
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
});
