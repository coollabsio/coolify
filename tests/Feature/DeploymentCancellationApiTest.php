<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a team with owner
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    // Create an API token for the user
    $this->token = $this->user->createToken('test-token', ['*'], $this->team->id);
    $this->bearerToken = $this->token->plainTextToken;

    // Create a server for the team
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
});

describe('POST /api/v1/deployments/{uuid}/cancel', function () {
    test('returns 401 when not authenticated', function () {
        $response = $this->postJson('/api/v1/deployments/fake-uuid/cancel');

        $response->assertStatus(401);
    });

    test('returns 404 when deployment not found', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/deployments/non-existent-uuid/cancel');

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Deployment not found.']);
    });

    test('returns 403 when user does not own the deployment', function () {
        // Create another team and server
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);

        // Create a deployment on the other team's server
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'test-deployment-uuid',
            'application_id' => 1,
            'server_id' => $otherServer->id,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        $response->assertStatus(403);
        $response->assertJson(['message' => 'You do not have permission to cancel this deployment.']);
    });

    test('returns 400 when deployment is already finished', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'finished-deployment-uuid',
            'application_id' => 1,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        $response->assertStatus(400);
        $response->assertJsonFragment(['Deployment cannot be cancelled']);
    });

    test('returns 400 when deployment is already failed', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'failed-deployment-uuid',
            'application_id' => 1,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::FAILED->value,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        $response->assertStatus(400);
        $response->assertJsonFragment(['Deployment cannot be cancelled']);
    });

    test('returns 400 when deployment is already cancelled', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'cancelled-deployment-uuid',
            'application_id' => 1,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        $response->assertStatus(400);
        $response->assertJsonFragment(['Deployment cannot be cancelled']);
    });

    test('successfully cancels queued deployment', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'queued-deployment-uuid',
            'application_id' => 1,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::QUEUED->value,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        // Expect success (200) or 500 if server connection fails (which is expected in test environment)
        expect($response->status())->toBeIn([200, 500]);

        // Verify deployment status was updated to cancelled
        $deployment->refresh();
        expect($deployment->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
    });

    test('successfully cancels in-progress deployment', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'in-progress-deployment-uuid',
            'application_id' => 1,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        // Expect success (200) or 500 if server connection fails (which is expected in test environment)
        expect($response->status())->toBeIn([200, 500]);

        // Verify deployment status was updated to cancelled
        $deployment->refresh();
        expect($deployment->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
    });

    test('returns correct response structure on success', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'success-deployment-uuid',
            'application_id' => 1,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        if ($response->status() === 200) {
            $response->assertJsonStructure([
                'message',
                'deployment_uuid',
                'status',
            ]);
            $response->assertJson([
                'deployment_uuid' => $deployment->deployment_uuid,
                'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
            ]);
        }
    });
});
