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
    InstanceSettings::create(['id' => 0, 'is_api_enabled' => true]);

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

function serviceContainerLabelAuthHeaders($bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

describe('PATCH /api/v1/services/{uuid}', function () {
    test('accepts is_container_label_escape_enabled field', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        $response = $this->withHeaders(serviceContainerLabelAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/services/{$service->uuid}", [
                'is_container_label_escape_enabled' => false,
            ]);

        $response->assertStatus(200);

        $service->refresh();
        expect($service->is_container_label_escape_enabled)->toBeFalse();
    });

    test('rejects invalid is_container_label_escape_enabled value', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        $response = $this->withHeaders(serviceContainerLabelAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/services/{$service->uuid}", [
                'is_container_label_escape_enabled' => 'not-a-boolean',
            ]);

        $response->assertStatus(422);
    });
});
