<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    // Create token manually since User::createToken relies on session('currentTeam')
    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);
});

describe('GET /api/v1/deployments/{uuid}', function () {
    test('returns 401 when not authenticated', function () {
        $response = $this->getJson('/api/v1/deployments/fake-uuid');

        $response->assertUnauthorized();
    });

    test('returns 404 when deployment not found', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/deployments/non-existent-uuid');

        $response->assertNotFound();
        $response->assertJson(['message' => 'Deployment not found.']);
    });

    test('returns deployment when uuid is valid and belongs to team', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'test-deploy-uuid',
            'application_id' => $this->application->id,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson("/api/v1/deployments/{$deployment->deployment_uuid}");

        $response->assertSuccessful();
        $response->assertJsonFragment(['deployment_uuid' => 'test-deploy-uuid']);
    });

    test('returns 404 when deployment belongs to another team', function () {
        $otherTeam = Team::factory()->create();
        $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
        $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
        $otherApplication = Application::factory()->create([
            'environment_id' => $otherEnvironment->id,
        ]);
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);

        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'other-team-deploy-uuid',
            'application_id' => $otherApplication->id,
            'server_id' => $otherServer->id,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson("/api/v1/deployments/{$deployment->deployment_uuid}");

        $response->assertNotFound();
    });
});
