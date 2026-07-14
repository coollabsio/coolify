<?php

use App\Actions\Service\DeployServiceApplication;
use App\Actions\Service\RestartServiceApplication;
use App\Actions\Service\StopServiceApplication;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->bearerToken = createBearerTokenForServiceApplicationsApiTest($this->user, $this->team);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function createBearerTokenForServiceApplicationsApiTest(User $user, Team $team, array $abilities = ['*']): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

function createServiceWithApplicationForApiTest(object $ctx): object
{
    $service = Service::factory()->create([
        'environment_id' => $ctx->environment->id,
        'server_id' => $ctx->server->id,
        'destination_id' => $ctx->destination->id,
        'destination_type' => $ctx->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]);

    $sa = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'web',
        'service_id' => $service->id,
        'image' => 'nginx:alpine',
    ]);

    return (object) ['service' => $service, 'serviceApplication' => $sa];
}

function createServiceWithoutApplicationsForApiTest(object $ctx): Service
{
    return Service::factory()->create([
        'environment_id' => $ctx->environment->id,
        'server_id' => $ctx->server->id,
        'destination_id' => $ctx->destination->id,
        'destination_type' => $ctx->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]);
}

describe('GET /api/v1/services/{uuid}/applications', function () {
    test('returns empty array when service has no applications', function () {
        $service = createServiceWithoutApplicationsForApiTest($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson("/api/v1/services/{$service->uuid}/applications");

        $response->assertStatus(200);
        $response->assertJson([]);
    });

    test('lists service applications for the service', function () {
        $ctx = createServiceWithApplicationForApiTest($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson("/api/v1/services/{$ctx->service->uuid}/applications");

        $response->assertStatus(200);
        $response->assertJsonFragment(['uuid' => $ctx->serviceApplication->uuid]);
    });

    test('returns 404 when service does not exist', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/services/00000000-0000-0000-0000-000000000001/applications');

        $response->assertStatus(404);
        $response->assertJsonFragment(['message' => 'Service not found.']);
    });

    test('does not list applications from another team', function () {
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
        $otherServer->settings->update([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
        ]);

        $otherCtx = (object) [
            'server' => $otherServer,
            'destination' => StandaloneDocker::where('server_id', $otherServer->id)->first(),
            'environment' => Environment::factory()->create([
                'project_id' => Project::factory()->create(['team_id' => $otherTeam->id])->id,
            ]),
        ];
        $ctx = createServiceWithApplicationForApiTest($otherCtx);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson("/api/v1/services/{$ctx->service->uuid}/applications");

        $response->assertStatus(404);
        $response->assertJsonFragment(['message' => 'Service not found.']);
    });
});

describe('GET /api/v1/services/{uuid}/applications/{app_uuid}', function () {
    test('returns 404 for unknown service', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/services/00000000-0000-0000-0000-000000000002/applications/non-existent-uuid-12345');

        $response->assertStatus(404);
        $response->assertJsonFragment(['message' => 'Service not found.']);
    });

    test('returns 404 when application uuid is not under service', function () {
        $ctx = createServiceWithApplicationForApiTest($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson("/api/v1/services/{$ctx->service->uuid}/applications/00000000-0000-0000-0000-000000000003");

        $response->assertStatus(404);
        $response->assertJsonFragment(['message' => 'Service application not found.']);
    });

    test('returns service application', function () {
        $ctx = createServiceWithApplicationForApiTest($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson("/api/v1/services/{$ctx->service->uuid}/applications/{$ctx->serviceApplication->uuid}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['uuid' => $ctx->serviceApplication->uuid, 'name' => 'web']);
    });
});

describe('PATCH /api/v1/services/{uuid}/applications/{app_uuid}', function () {
    test('returns 400 without valid token', function () {
        $response = $this->patchJson('/api/v1/services/some-uuid/applications/some-app', [
            'human_name' => 'x',
        ], ['Accept' => 'application/json', 'Content-Type' => 'application/json']);

        $response->assertStatus(401);
    });

    test('updates human_name', function () {
        $ctx = createServiceWithApplicationForApiTest($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->patchJson("/api/v1/services/{$ctx->service->uuid}/applications/{$ctx->serviceApplication->uuid}", [
            'human_name' => 'Web UI',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['human_name' => 'Web UI']);
        $ctx->serviceApplication->refresh();
        expect($ctx->serviceApplication->human_name)->toBe('Web UI');
    });

    test('returns 422 for invalid url scheme', function () {
        $ctx = createServiceWithApplicationForApiTest($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->patchJson("/api/v1/services/{$ctx->service->uuid}/applications/{$ctx->serviceApplication->uuid}", [
            'url' => 'ftp://example.com',
        ]);

        $response->assertStatus(422);
    });

    test('returns 422 when enabling log drain but server has no log drain', function () {
        $ctx = createServiceWithApplicationForApiTest($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->patchJson("/api/v1/services/{$ctx->service->uuid}/applications/{$ctx->serviceApplication->uuid}", [
            'is_log_drain_enabled' => true,
        ]);

        $response->assertStatus(422);
        expect((string) $response->json('errors.is_log_drain_enabled.0'))->toContain('Log drain');
    });

    test('read-only token cannot update a service application', function () {
        $ctx = createServiceWithApplicationForApiTest($this);
        $readOnlyToken = createBearerTokenForServiceApplicationsApiTest($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$readOnlyToken,
        ])->patchJson("/api/v1/services/{$ctx->service->uuid}/applications/{$ctx->serviceApplication->uuid}", [
            'human_name' => 'Blocked',
        ]);

        $response->assertStatus(403);
    });

    test('returns 409 for domain conflicts unless forced', function () {
        $ctx = createServiceWithApplicationForApiTest($this);
        $ctx->serviceApplication->update(['fqdn' => 'http://used.example.com']);
        $otherApplication = ServiceApplication::create([
            'name' => 'admin',
            'service_id' => $ctx->service->id,
            'image' => 'nginx:alpine',
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->patchJson("/api/v1/services/{$ctx->service->uuid}/applications/{$otherApplication->uuid}", [
            'url' => 'http://used.example.com',
        ])->assertStatus(409);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->patchJson("/api/v1/services/{$ctx->service->uuid}/applications/{$otherApplication->uuid}?force_domain_override=true", [
            'url' => 'http://used.example.com',
        ])->assertStatus(200);

        $otherApplication->refresh();
        expect($otherApplication->fqdn)->toBe('http://used.example.com');
    });
});

describe('POST /api/v1/services/{uuid}/applications/{app_uuid}/restart', function () {
    test('returns 400 without valid token', function () {
        $response = $this->postJson('/api/v1/services/some-uuid/applications/some-app/restart');

        $response->assertStatus(401);
    });

    test('queues restart for a service application', function () {
        $ctx = createServiceWithApplicationForApiTest($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->postJson("/api/v1/services/{$ctx->service->uuid}/applications/{$ctx->serviceApplication->uuid}/restart");

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Service application restart request queued.']);
        RestartServiceApplication::assertPushed();
    });
});

describe('POST /api/v1/services/{uuid}/applications/{app_uuid}/start', function () {
    test('write token cannot start a service application', function () {
        $ctx = createServiceWithApplicationForApiTest($this);
        $writeToken = createBearerTokenForServiceApplicationsApiTest($this->user, $this->team, ['write']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$writeToken,
        ])->postJson("/api/v1/services/{$ctx->service->uuid}/applications/{$ctx->serviceApplication->uuid}/start");

        $response->assertStatus(403);
    });

    test('queues deploy for a service application', function () {
        $ctx = createServiceWithApplicationForApiTest($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->postJson("/api/v1/services/{$ctx->service->uuid}/applications/{$ctx->serviceApplication->uuid}/start?latest=1&force=1");

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Service application deploy request queued.']);
        DeployServiceApplication::assertPushed();
    });
});

describe('POST /api/v1/services/{uuid}/applications/{app_uuid}/stop', function () {
    test('queues stop for a service application', function () {
        $ctx = createServiceWithApplicationForApiTest($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->postJson("/api/v1/services/{$ctx->service->uuid}/applications/{$ctx->serviceApplication->uuid}/stop");

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Service application stop request queued.']);
        StopServiceApplication::assertPushed();
    });
});

describe('GET /api/v1/services/{uuid}/applications/{app_uuid}/logs', function () {
    test('returns 400 when server is not functional', function () {
        $ctx = createServiceWithApplicationForApiTest($this);
        $this->server->settings->update([
            'is_reachable' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson("/api/v1/services/{$ctx->service->uuid}/applications/{$ctx->serviceApplication->uuid}/logs");

        $response->assertStatus(400);
        $response->assertJsonFragment(['message' => 'Server is not functional.']);
    });
});
