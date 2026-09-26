<?php

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
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0, 'is_api_enabled' => true]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first();
});

function serviceConnectToDockerNetworkAuthHeaders($bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

describe('POST /api/v1/services connect_to_docker_network', function () {
    test('accepts connect_to_docker_network=true when creating a custom service', function () {
        $source = "services:\n  app:\n    image: nginx:alpine\n";

        $response = $this->withHeaders(serviceConnectToDockerNetworkAuthHeaders($this->bearerToken))
            ->postJson('/api/v1/services', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'docker_compose_raw' => base64_encode($source),
                'connect_to_docker_network' => true,
            ]);

        $response->assertStatus(201);

        $service = Service::whereUuid($response->json('uuid'))->firstOrFail();
        expect($service->connect_to_docker_network)->toBeTrue();
    });

    test('accepts connect_to_docker_network=false when creating a custom service', function () {
        $source = "services:\n  app:\n    image: nginx:alpine\n";

        $response = $this->withHeaders(serviceConnectToDockerNetworkAuthHeaders($this->bearerToken))
            ->postJson('/api/v1/services', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'docker_compose_raw' => base64_encode($source),
                'connect_to_docker_network' => false,
            ]);

        $response->assertStatus(201);

        $service = Service::whereUuid($response->json('uuid'))->firstOrFail();
        expect($service->connect_to_docker_network)->toBeFalse();
    });

    test('rejects non-boolean connect_to_docker_network value', function () {
        $source = "services:\n  app:\n    image: nginx:alpine\n";

        $response = $this->withHeaders(serviceConnectToDockerNetworkAuthHeaders($this->bearerToken))
            ->postJson('/api/v1/services', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'docker_compose_raw' => base64_encode($source),
                'connect_to_docker_network' => 'not-a-boolean',
            ]);

        $response->assertStatus(422);
    });
});
