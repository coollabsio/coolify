<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Team A (current actor)
    $this->userA = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->userA->teams()->attach($this->teamA, ['role' => 'owner']);
    $this->serverA = Server::factory()->create(['team_id' => $this->teamA->id]);
    $this->destinationA = StandaloneDocker::factory()->create(['server_id' => $this->serverA->id, 'network' => 'net-a-'.fake()->uuid()]);
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);

    // Team B (other team)
    $this->userB = User::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->userB->teams()->attach($this->teamB, ['role' => 'owner']);
    $this->serverB = Server::factory()->create(['team_id' => $this->teamB->id]);
    $this->destinationB = StandaloneDocker::factory()->create(['server_id' => $this->serverB->id, 'network' => 'net-b-'.fake()->uuid()]);
    $this->projectB = Project::factory()->create(['team_id' => $this->teamB->id]);
    $this->environmentB = Environment::factory()->create(['project_id' => $this->projectB->id]);

    // Authenticate as Team A
    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);
});

test('unscoped Project lookup returns another teams project', function () {
    $project = Project::where('uuid', $this->projectB->uuid)->first();

    expect($project)->not->toBeNull()
        ->and($project->team_id)->toBe($this->teamB->id)
        ->and($project->team_id)->not->toBe($this->teamA->id);
});

test('unscoped StandaloneDocker lookup returns another teams destination', function () {
    $dest = StandaloneDocker::where('uuid', $this->destinationB->uuid)->first();

    expect($dest)->not->toBeNull()
        ->and($dest->server->team_id)->toBe($this->teamB->id);
});

test('ownedByCurrentTeam scope blocks other-team Project access', function () {
    expect(Project::ownedByCurrentTeam()->where('uuid', $this->projectB->uuid)->first())->toBeNull();
});

test('ownedByCurrentTeam scope allows own Project access', function () {
    expect(Project::ownedByCurrentTeam()->where('uuid', $this->projectA->uuid)->first())->not->toBeNull();
});

test('Team A can create Application in Team B environment via unscoped lookups', function () {
    $destination = StandaloneDocker::where('uuid', $this->destinationB->uuid)->first();
    $project = Project::where('uuid', $this->projectB->uuid)->first();
    $environment = $project->load(['environments'])->environments->where('uuid', $this->environmentB->uuid)->first();

    $application = Application::create([
        'name' => 'team-scope-test-canary',
        'repository_project_id' => 0,
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
        'build_pack' => 'dockerfile',
        'dockerfile' => "FROM alpine\nCMD echo hello",
        'ports_exposes' => 80,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'health_check_enabled' => false,
        'source_id' => 0,
        'source_type' => GithubApp::class,
    ]);

    expect($application->environment_id)->toBe($this->environmentB->id)
        ->and($application->destination_id)->toBe($this->destinationB->id)
        ->and($application->environment->project->team->id)->toBe($this->teamB->id)
        ->and($application->environment->project->team->id)->not->toBe($this->teamA->id);
});

test('resource creation page loads with another teams project UUID', function () {
    $response = $this->get(route('project.resource.create', [
        'project_uuid' => $this->projectB->uuid,
        'environment_uuid' => $this->environmentB->uuid,
    ]));

    expect($response->status())->not->toBe(403);
});
