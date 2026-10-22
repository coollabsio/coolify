<?php

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0, 'is_api_enabled' => true]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('test-token', ['*'])->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first();
});

function createServiceWithDockerNetworkFlag(mixed $connectToDockerNetwork, string $bearerToken, array $payload): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.$bearerToken])
        ->postJson('/api/v1/services', [
            ...$payload,
            'connect_to_docker_network' => $connectToDockerNetwork,
        ]);
}

describe('POST /api/v1/services connect_to_docker_network', function () {
    test('saves the flag for a docker_compose_raw service', function () {
        $response = createServiceWithDockerNetworkFlag(true, $this->bearerToken, [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_compose_raw' => base64_encode("services:\n  app:\n    image: nginx:alpine\n"),
        ]);

        $response->assertCreated();
        expect(Service::whereUuid($response->json('uuid'))->firstOrFail()->connect_to_docker_network)->toBeTruthy();
    });

    test('saves the flag for a one-click service', function () {
        Queue::fake();

        $response = createServiceWithDockerNetworkFlag(true, $this->bearerToken, [
            'type' => 'cyberchef',
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
        ]);

        $response->assertCreated();
        expect(Service::whereUuid($response->json('uuid'))->firstOrFail()->connect_to_docker_network)->toBeTruthy();
    });

    test('rejects a non-boolean value', function () {
        $response = createServiceWithDockerNetworkFlag('not-a-boolean', $this->bearerToken, [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_compose_raw' => base64_encode("services:\n  app:\n    image: nginx:alpine\n"),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['connect_to_docker_network']);
        expect($response->json('errors.connect_to_docker_network'))->not->toContain('This field is not allowed.');
    });
});
