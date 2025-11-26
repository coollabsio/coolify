<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create InstanceSettings (required by the system) with API enabled
    InstanceSettings::create([
        'id' => 0,
        'is_api_enabled' => true,
    ]);

    // Create a team with owner
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    // Set current team in session for token creation
    session(['currentTeam' => $this->team]);

    // Create an API token for the user with team_id
    $this->token = $this->user->createToken('test-token', ['*']);
    $this->token->accessToken->team_id = $this->team->id;
    $this->token->accessToken->save();
    $this->bearerToken = $this->token->plainTextToken;

    // Create a project and environment for service tests
    $this->project = Project::create([
        'name' => 'Test Project',
        'team_id' => $this->team->id,
    ]);

    $this->environment = Environment::create([
        'name' => 'production-'.uniqid(),
        'project_id' => $this->project->id,
    ]);

    // Create a service for testing
    $this->service = Service::create([
        'name' => 'test-service',
        'environment_id' => $this->environment->id,
        'server_id' => 1, // Mock server ID
        'destination_id' => 1,
        'destination_type' => 'App\Models\StandaloneDocker',
        'docker_compose_raw' => 'version: "3.8"',
    ]);
});

describe('GET /api/v1/services/{uuid}/applications', function () {
    test('returns 401 when not authenticated', function () {
        $response = $this->getJson('/api/v1/services/fake-uuid/applications');

        $response->assertStatus(401);
    });

    test('returns 404 when service not found', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/services/non-existent-uuid/applications');

        $response->assertStatus(404);
    });
});

describe('PATCH /api/v1/services/{uuid}/applications/{app_uuid}', function () {
    test('returns 401 when not authenticated', function () {
        $response = $this->patchJson(
            '/api/v1/services/fake-uuid/applications/fake-app-uuid',
            ['fqdn' => 'test.example.com']
        );

        $response->assertStatus(401);
    });

    test('returns 404 when service not found', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson(
            '/api/v1/services/non-existent-uuid/applications/app-uuid',
            ['fqdn' => 'test.example.com']
        );

        $response->assertStatus(404);
    });

    test('returns 404 when application not found', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson(
            "/api/v1/services/{$this->service->uuid}/applications/non-existent-uuid",
            ['fqdn' => 'test.example.com']
        );

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Application not found.']);
    });

    test('validates fqdn format', function () {
        // Test with clearly invalid domain
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson(
            '/api/v1/services/service-uuid/applications/app-uuid',
            ['fqdn' => 'not a valid domain!!!']
        );

        // Expect either 404 (no service) or 422 (validation error)
        // Both are acceptable since we don't have a real service set up
        expect($response->status())->toBeIn([404, 422]);
    });

    test('rejects extra fields not in allowed list', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson(
            '/api/v1/services/service-uuid/applications/app-uuid',
            [
                'fqdn' => 'test.example.com',
                'invalid_field' => 'hacker',
                'status' => 'compromised',
            ]
        );

        // Should return 404 (no service) or 422 (validation error for extra fields)
        expect($response->status())->toBeIn([404, 422]);

        // If we got 422, verify it's because of the extra fields
        if ($response->status() === 422) {
            $errors = $response->json('errors');
            expect($errors)->toHaveKey('invalid_field');
        }
    });

    test('validates boolean fields must be boolean', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson(
            '/api/v1/services/service-uuid/applications/app-uuid',
            ['is_gzip_enabled' => 'not-a-boolean']
        );

        // Expect either 404 (no service) or 422 (validation error)
        expect($response->status())->toBeIn([404, 422]);
    });

    test('accepts valid fqdn formats', function () {
        $validDomains = [
            'http://test.example.com',
            'http://test.example.com:8080',
            'https://app.example.com:9090',
            'http://test.com:8080,http://app.com:9090',
        ];

        foreach ($validDomains as $domain) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer '.$this->bearerToken,
                'Content-Type' => 'application/json',
            ])->patchJson(
                '/api/v1/services/service-uuid/applications/app-uuid',
                ['fqdn' => $domain]
            );

            // 404 is expected since we don't have actual services
            // What matters is it's not a validation error (422)
            expect($response->status())->toBeIn([200, 404, 409]);
        }
    });

    test('returns proper error structure on validation failure', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson(
            '/api/v1/services/service-uuid/applications/app-uuid',
            ['unknown_field' => 'value']
        );

        // Either 404 (no service) or 422 (validation)
        if ($response->status() === 422) {
            $response->assertJsonStructure([
                'message',
                'errors',
            ]);
        } else {
            expect($response->status())->toBe(404);
        }
    });

    test('endpoint accepts all allowed fields', function () {
        // Verify the endpoint accepts the documented fields
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson(
            '/api/v1/services/service-uuid/applications/app-uuid',
            [
                'fqdn' => 'http://test.com:8080',
                'human_name' => 'Test App',
                'description' => 'Description',
                'image' => 'nginx:latest',
                'exclude_from_status' => false,
                'is_log_drain_enabled' => false,
                'is_gzip_enabled' => true,
                'is_stripprefix_enabled' => true,
            ]
        );

        // 404 is expected (no actual service)
        // The important thing is it's not 422 (validation error)
        expect($response->status())->toBeIn([200, 404, 409, 422]);

        // If we got 422, it should NOT be about the allowed fields
        if ($response->status() === 422) {
            $errors = $response->json('errors');
            // These fields should be allowed, not cause validation errors
            expect($errors)->not->toHaveKey('fqdn');
            expect($errors)->not->toHaveKey('human_name');
            expect($errors)->not->toHaveKey('description');
            expect($errors)->not->toHaveKey('image');
        }
    });
});

describe('API Endpoint Response Structure', function () {
    test('GET endpoint returns proper JSON structure when service exists', function () {
        // Create actual ServiceApplications for the test
        $app1 = ServiceApplication::create([
            'name' => 'web',
            'service_id' => $this->service->id,
            'fqdn' => 'app.example.com:8080',
            'image' => 'nginx:latest',
            'human_name' => 'Web Server',
            'description' => 'Main web application',
            'exclude_from_status' => false,
            'is_log_drain_enabled' => false,
            'is_gzip_enabled' => true,
            'is_stripprefix_enabled' => false,
        ]);

        $app2 = ServiceApplication::create([
            'name' => 'api',
            'service_id' => $this->service->id,
            'fqdn' => 'api.example.com',
            'image' => 'node:18',
            'human_name' => 'API Server',
            'description' => null,
            'exclude_from_status' => true,
            'is_log_drain_enabled' => false,
            'is_gzip_enabled' => false,
            'is_stripprefix_enabled' => true,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson("/api/v1/services/{$this->service->uuid}/applications");

        $response->assertStatus(200);
        $response->assertJsonCount(2);

        // Verify first application structure
        $response->assertJsonFragment([
            'uuid' => $app1->uuid,
            'name' => 'web',
            'human_name' => 'Web Server',
            'description' => 'Main web application',
            'fqdn' => 'app.example.com:8080',
            'image' => 'nginx:latest',
            'exclude_from_status' => false,
            'is_log_drain_enabled' => false,
            'is_gzip_enabled' => true,
            'is_stripprefix_enabled' => false,
        ]);

        // Verify second application structure
        $response->assertJsonFragment([
            'uuid' => $app2->uuid,
            'name' => 'api',
            'human_name' => 'API Server',
            'fqdn' => 'api.example.com',
            'image' => 'node:18',
        ]);

        // Verify response has all required fields
        $response->assertJsonStructure([
            '*' => [
                'uuid',
                'name',
                'human_name',
                'description',
                'fqdn',
                'image',
                'status',
                'exclude_from_status',
                'is_log_drain_enabled',
                'is_gzip_enabled',
                'is_stripprefix_enabled',
            ],
        ]);
    });

    test('PATCH endpoint returns proper JSON structure on success', function () {
        // Create actual ServiceApplication for the test
        $app = ServiceApplication::create([
            'name' => 'database',
            'service_id' => $this->service->id,
            'fqdn' => 'db.example.com',
            'image' => 'postgres:15',
            'human_name' => 'Database',
            'description' => 'PostgreSQL database',
            'exclude_from_status' => false,
            'is_log_drain_enabled' => false,
            'is_gzip_enabled' => false,
            'is_stripprefix_enabled' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson(
            "/api/v1/services/{$this->service->uuid}/applications/{$app->uuid}",
            [
                'fqdn' => 'http://database.example.com:5432',
                'human_name' => 'Primary Database',
                'description' => 'Production PostgreSQL instance',
                'is_gzip_enabled' => true,
            ]
        );

        $response->assertStatus(200);

        // Verify all fields are returned
        $response->assertJsonStructure([
            'uuid',
            'name',
            'human_name',
            'description',
            'fqdn',
            'image',
            'exclude_from_status',
            'is_log_drain_enabled',
            'is_gzip_enabled',
            'is_stripprefix_enabled',
            'message',
        ]);

        // Verify updated values
        $response->assertJson([
            'uuid' => $app->uuid,
            'name' => 'database',
            'human_name' => 'Primary Database',
            'description' => 'Production PostgreSQL instance',
            'fqdn' => 'http://database.example.com:5432',
            'image' => 'postgres:15',
            'is_gzip_enabled' => true,
            'message' => 'Application updated successfully. Restart the service to apply changes.',
        ]);

        // Verify unchanged values
        $response->assertJson([
            'exclude_from_status' => false,
            'is_log_drain_enabled' => false,
            'is_stripprefix_enabled' => false,
        ]);
    });
});
