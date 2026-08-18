<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    // Attacker: Team A
    $this->userA = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->userA->teams()->attach($this->teamA, ['role' => 'owner']);

    $this->serverA = Server::factory()->create(['team_id' => $this->teamA->id]);
    $this->destinationA = StandaloneDocker::factory()->create(['server_id' => $this->serverA->id]);
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);

    // Victim: Team B
    $this->teamB = Team::factory()->create();
    $this->serverB = Server::factory()->create(['team_id' => $this->teamB->id]);
    $this->destinationB = StandaloneDocker::factory()->create(['server_id' => $this->serverB->id]);
    $this->projectB = Project::factory()->create(['team_id' => $this->teamB->id]);
    $this->environmentB = Environment::factory()->create(['project_id' => $this->projectB->id]);

    $this->victimApplication = Application::factory()->create([
        'environment_id' => $this->environmentB->id,
        'destination_id' => $this->destinationB->id,
        'destination_type' => $this->destinationB->getMorphClass(),
    ]);

    $this->victimService = Service::factory()->create([
        'environment_id' => $this->environmentB->id,
        'destination_id' => $this->destinationB->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    // Act as attacker
    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);
});

test('cannot access logs of application from another team', function () {
    $response = $this->get(route('project.application.logs', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'application_uuid' => $this->victimApplication->uuid,
    ]));

    $response->assertStatus(404);
});

test('cannot access logs of service from another team', function () {
    $response = $this->get(route('project.service.logs', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $this->victimService->uuid,
    ]));

    $response->assertStatus(404);
});

test('can access logs of own application', function () {
    $ownApplication = Application::factory()->create([
        'environment_id' => $this->environmentA->id,
        'destination_id' => $this->destinationA->id,
        'destination_type' => $this->destinationA->getMorphClass(),
    ]);

    $response = $this->get(route('project.application.logs', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'application_uuid' => $ownApplication->uuid,
    ]));

    $response->assertStatus(200);
});

test('can access logs of own service', function () {
    $ownService = Service::factory()->create([
        'environment_id' => $this->environmentA->id,
        'destination_id' => $this->destinationA->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->get(route('project.service.logs', [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
        'service_uuid' => $ownService->uuid,
    ]));

    $response->assertStatus(200);
});
