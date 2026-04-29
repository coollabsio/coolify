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

    session(['currentTeam' => $this->team]);
    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
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
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->first();
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

test('returns 404 when server uuid belongs to another team', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);

    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->withHeaders(authHeaders())
        ->getJson("/api/v1/servers/{$otherServer->uuid}/domains");

    $response->assertNotFound();
    $response->assertJson(['message' => 'Server not found.']);
});

test('only returns domains for applications on the specified server', function () {
    $application = Application::factory()->create([
        'fqdn' => 'https://app-on-server.example.com',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->first();

    $applicationOnOtherServer = Application::factory()->create([
        'fqdn' => 'https://app-on-other-server.example.com',
        'environment_id' => $this->environment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
    ]);

    $response = $this->withHeaders(authHeaders())
        ->getJson("/api/v1/servers/{$this->server->uuid}/domains");

    $response->assertOk();
    $responseContent = $response->json();
    $allDomains = collect($responseContent)->pluck('domains')->flatten()->toArray();
    expect($allDomains)->toContain('app-on-server.example.com');
    expect($allDomains)->not->toContain('app-on-other-server.example.com');
});
