<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) new Cuid2, 'name' => 'test-docker']
        );
    });

    $this->project = Project::create([
        'uuid' => (string) new Cuid2,
        'name' => 'test-project',
        'team_id' => $this->team->id,
    ]);

    $this->environment = $this->project->environments()->first();

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function deploymentAuthHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

describe('GET /api/v1/deployments/{uuid}', function () {
    test('returns 401 when not authenticated', function () {
        $response = $this->getJson('/api/v1/deployments/some-uuid');

        $response->assertUnauthorized();
    });

    test('returns 404 when deployment not found', function () {
        $response = $this->withHeaders(deploymentAuthHeaders($this->bearerToken))
            ->getJson('/api/v1/deployments/non-existent-uuid');

        $response->assertNotFound();
        $response->assertJson(['message' => 'Deployment not found.']);
    });

    test('returns 404 when deployment belongs to another team', function () {
        $otherTeam = Team::factory()->create();
        $otherProject = Project::create([
            'uuid' => (string) new Cuid2,
            'name' => 'other-project',
            'team_id' => $otherTeam->id,
        ]);
        $otherEnvironment = $otherProject->environments()->first();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);

        $otherDestination = StandaloneDocker::firstOrCreate(
            ['server_id' => $otherServer->id, 'network' => 'coolify'],
            ['uuid' => (string) new Cuid2, 'name' => 'other-docker']
        );
        $otherApplication = Application::factory()->create([
            'environment_id' => $otherEnvironment->id,
            'destination_id' => $otherDestination->id,
            'destination_type' => $otherDestination->getMorphClass(),
        ]);

        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'other-team-deployment-uuid',
            'application_id' => $otherApplication->id,
            'server_id' => $otherServer->id,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        $response = $this->withHeaders(deploymentAuthHeaders($this->bearerToken))
            ->getJson("/api/v1/deployments/{$deployment->deployment_uuid}");

        $response->assertNotFound();
        $response->assertJson(['message' => 'Deployment not found.']);
    });

    test('returns deployment when it belongs to the token team', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'own-team-deployment-uuid',
            'application_id' => $this->application->id,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        $response = $this->withHeaders(deploymentAuthHeaders($this->bearerToken))
            ->getJson("/api/v1/deployments/{$deployment->deployment_uuid}");

        $response->assertOk();
        $response->assertJsonFragment(['deployment_uuid' => 'own-team-deployment-uuid']);
    });
});
