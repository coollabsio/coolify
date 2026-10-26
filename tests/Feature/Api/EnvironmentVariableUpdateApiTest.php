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

describe('PATCH /api/v1/services/{uuid}/envs', function () {
    test('returns the updated environment variable object', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        EnvironmentVariable::create([
            'key' => 'APP_IMAGE_TAG',
            'value' => 'old-value',
            'resourceable_type' => Service::class,
            'resourceable_id' => $service->id,
            'is_preview' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/services/{$service->uuid}/envs", [
            'key' => 'APP_IMAGE_TAG',
            'value' => 'new-value',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'uuid',
            'key',
            'is_literal',
            'is_multiline',
            'is_shown_once',
            'created_at',
            'updated_at',
        ]);
        $response->assertJsonFragment(['key' => 'APP_IMAGE_TAG']);
        $response->assertJsonMissing(['message' => 'Environment variable updated.']);
    });

    test('returns 404 when environment variable does not exist', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/services/{$service->uuid}/envs", [
            'key' => 'NONEXISTENT_KEY',
            'value' => 'some-value',
        ]);

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Environment variable not found.']);
    });

    test('returns 404 when service does not exist', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson('/api/v1/services/non-existent-uuid/envs', [
            'key' => 'APP_IMAGE_TAG',
            'value' => 'some-value',
        ]);

        $response->assertStatus(404);
    });

    test('returns 422 when key is missing', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/services/{$service->uuid}/envs", [
            'value' => 'some-value',
        ]);

        $response->assertStatus(422);
    });

    test('uses route uuid and ignores uuid in request body', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        EnvironmentVariable::create([
            'key' => 'TEST_KEY',
            'value' => 'old-value',
            'resourceable_type' => Service::class,
            'resourceable_id' => $service->id,
            'is_preview' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/services/{$service->uuid}/envs", [
            'key' => 'TEST_KEY',
            'value' => 'new-value',
            'uuid' => 'bogus-uuid-from-body',
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['key' => 'TEST_KEY']);
    });
});

describe('PATCH /api/v1/applications/{uuid}/envs', function () {
    test('returns the updated environment variable object', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        EnvironmentVariable::create([
            'key' => 'APP_IMAGE_TAG',
            'value' => 'old-value',
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
            'is_preview' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/applications/{$application->uuid}/envs", [
            'key' => 'APP_IMAGE_TAG',
            'value' => 'new-value',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'uuid',
            'key',
            'is_literal',
            'is_multiline',
            'is_shown_once',
            'created_at',
            'updated_at',
        ]);
        $response->assertJsonFragment(['key' => 'APP_IMAGE_TAG']);
        $response->assertJsonMissing(['message' => 'Environment variable updated.']);
    });

    test('returns 404 when environment variable does not exist', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/applications/{$application->uuid}/envs", [
            'key' => 'NONEXISTENT_KEY',
            'value' => 'some-value',
        ]);

        $response->assertStatus(404);
    });

    test('returns 422 when key is missing', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/applications/{$application->uuid}/envs", [
            'value' => 'some-value',
        ]);

        $response->assertStatus(422);
    });

    test('rejects unknown fields in request body', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        EnvironmentVariable::create([
            'key' => 'TEST_KEY',
            'value' => 'old-value',
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
            'is_preview' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/applications/{$application->uuid}/envs", [
            'key' => 'TEST_KEY',
            'value' => 'new-value',
            'uuid' => 'bogus-uuid-from-body',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['uuid' => ['This field is not allowed.']]);
    });
});

describe('application env PATCH keeps omitted flags', function () {
    beforeEach(function () {
        $this->application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $this->env = EnvironmentVariable::create([
            'key' => 'FLAGGED_KEY',
            'value' => 'old-value',
            'resourceable_type' => Application::class,
            'resourceable_id' => $this->application->id,
            'is_preview' => false,
            'is_literal' => true,
            'is_multiline' => true,
            'is_shown_once' => true,
        ]);

        $this->headers = [
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ];
    });

    test('single update with only key and value keeps flags', function () {
        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/applications/{$this->application->uuid}/envs", [
                'key' => 'FLAGGED_KEY',
                'value' => 'new-value',
            ])
            ->assertStatus(201);

        $env = $this->env->fresh();
        expect($env->value)->toBe('new-value')
            ->and((bool) $env->is_literal)->toBeTrue()
            ->and($env->is_multiline)->toBeTrue()
            ->and((bool) $env->is_shown_once)->toBeTrue();
    });

    test('bulk update with only key and value keeps flags', function () {
        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/applications/{$this->application->uuid}/envs/bulk", [
                'data' => [['key' => 'FLAGGED_KEY', 'value' => 'new-value']],
            ])
            ->assertStatus(201);

        $env = $this->env->fresh();
        expect($env->value)->toBe('new-value')
            ->and((bool) $env->is_literal)->toBeTrue()
            ->and($env->is_multiline)->toBeTrue()
            ->and((bool) $env->is_shown_once)->toBeTrue();
    });

    test('explicit flags are still applied', function () {
        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/applications/{$this->application->uuid}/envs", [
                'key' => 'FLAGGED_KEY',
                'value' => 'new-value',
                'is_literal' => false,
                'is_multiline' => false,
                'is_shown_once' => false,
            ])
            ->assertStatus(201);

        $env = $this->env->fresh();
        expect((bool) $env->is_literal)->toBeFalse()
            ->and($env->is_multiline)->toBeFalse()
            ->and((bool) $env->is_shown_once)->toBeFalse();
    });
});

describe('environment variable key validation for app and service APIs', function () {
    test('rejects invalid service environment variable keys on create update and bulk', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        EnvironmentVariable::create([
            'key' => 'SAFE_KEY',
            'value' => 'old-value',
            'resourceable_type' => Service::class,
            'resourceable_id' => $service->id,
            'is_preview' => false,
        ]);

        $headers = [
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ];

        $this->withHeaders($headers)
            ->postJson("/api/v1/services/{$service->uuid}/envs", [
                'key' => 'BAD$(id)',
                'value' => '1',
            ])
            ->assertStatus(422);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/services/{$service->uuid}/envs", [
                'key' => 'BAD$(id)',
                'value' => '1',
            ])
            ->assertStatus(422);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/services/{$service->uuid}/envs/bulk", [
                'data' => [[
                    'key' => 'BAD$(id)',
                    'value' => '1',
                ]],
            ])
            ->assertStatus(422);
    });

    test('rejects invalid application environment variable keys on create update and bulk', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        EnvironmentVariable::create([
            'key' => 'SAFE_KEY',
            'value' => 'old-value',
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
            'is_preview' => false,
        ]);

        $headers = [
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ];

        $this->withHeaders($headers)
            ->postJson("/api/v1/applications/{$application->uuid}/envs", [
                'key' => 'BAD$(id)',
                'value' => '1',
            ])
            ->assertStatus(422);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/applications/{$application->uuid}/envs", [
                'key' => 'BAD$(id)',
                'value' => '1',
            ])
            ->assertStatus(422);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/applications/{$application->uuid}/envs/bulk", [
                'data' => [[
                    'key' => 'BAD$(id)',
                    'value' => '1',
                ]],
            ])
            ->assertStatus(422);
    });
});
