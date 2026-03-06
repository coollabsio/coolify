<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->token = $this->user->createToken('test-token', ['*'], $this->team->id);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create(['server_id' => $this->server->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function authHeaders(): array
{
    return [
        'Authorization' => 'Bearer '.test()->bearerToken,
    ];
}

test('returns domains for own team application via uuid query param', function () {
    $application = Application::factory()->create([
        'fqdn' => 'https://my-app.example.com',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $response = $this->withHeaders(authHeaders())
        ->getJson("/api/v1/servers/{$this->server->uuid}/domains?uuid={$application->uuid}");

    $response->assertOk();
    $response->assertJsonFragment(['my-app.example.com']);
});

test('returns 404 when application uuid belongs to another team', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);

    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::factory()->create(['server_id' => $otherServer->id]);
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);

    $otherApplication = Application::factory()->create([
        'fqdn' => 'https://secret-app.internal.company.com',
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
    ]);

    $response = $this->withHeaders(authHeaders())
        ->getJson("/api/v1/servers/{$this->server->uuid}/domains?uuid={$otherApplication->uuid}");

    $response->assertNotFound();
    $response->assertJson(['message' => 'Application not found.']);
});

test('returns 404 for nonexistent application uuid', function () {
    $response = $this->withHeaders(authHeaders())
        ->getJson("/api/v1/servers/{$this->server->uuid}/domains?uuid=nonexistent-uuid");

    $response->assertNotFound();
    $response->assertJson(['message' => 'Application not found.']);
});
