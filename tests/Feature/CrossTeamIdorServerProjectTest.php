<?php

use App\Livewire\Boarding\Index as BoardingIndex;
use App\Livewire\GlobalSearch;
use App\Livewire\Project\CloneMe;
use App\Livewire\Project\DeleteProject;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Attacker: Team A
    $this->userA = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->userA->teams()->attach($this->teamA, ['role' => 'owner']);

    $this->serverA = Server::factory()->create(['team_id' => $this->teamA->id]);
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);

    // Victim: Team B
    $this->userB = User::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->userB->teams()->attach($this->teamB, ['role' => 'owner']);

    $this->serverB = Server::factory()->create(['team_id' => $this->teamB->id]);
    $this->projectB = Project::factory()->create(['team_id' => $this->teamB->id]);
    $this->environmentB = Environment::factory()->create(['project_id' => $this->projectB->id]);

    // Act as attacker (Team A)
    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);
});

describe('Boarding Server IDOR (GHSA-qfcc-2fm3-9q42)', function () {
    test('boarding mount cannot load server from another team via selectedExistingServer', function () {
        $component = Livewire::test(BoardingIndex::class, [
            'selectedServerType' => 'remote',
            'selectedExistingServer' => $this->serverB->id,
        ]);

        // The server from Team B should NOT be loaded
        expect($component->get('createdServer'))->toBeNull();
    });

    test('boarding mount can load own team server via selectedExistingServer', function () {
        $component = Livewire::test(BoardingIndex::class, [
            'selectedServerType' => 'remote',
            'selectedExistingServer' => $this->serverA->id,
        ]);

        // Own team server should load successfully
        expect($component->get('createdServer'))->not->toBeNull();
        expect($component->get('createdServer')->id)->toBe($this->serverA->id);
    });
});

describe('Boarding Project IDOR (GHSA-qfcc-2fm3-9q42)', function () {
    test('boarding mount cannot load project from another team via selectedProject', function () {
        $component = Livewire::test(BoardingIndex::class, [
            'selectedProject' => $this->projectB->id,
        ]);

        // The project from Team B should NOT be loaded
        expect($component->get('createdProject'))->toBeNull();
    });

    test('boarding selectExistingProject cannot load project from another team', function () {
        $component = Livewire::test(BoardingIndex::class)
            ->set('selectedProject', $this->projectB->id)
            ->call('selectExistingProject');

        expect($component->get('createdProject'))->toBeNull();
        $component->assertDispatched('error');
    });

    test('boarding selectExistingProject can load own team project', function () {
        $component = Livewire::test(BoardingIndex::class)
            ->set('selectedProject', $this->projectA->id)
            ->call('selectExistingProject');

        expect($component->get('createdProject'))->not->toBeNull();
        expect($component->get('createdProject')->id)->toBe($this->projectA->id);
    });
});

describe('GlobalSearch Server IDOR (GHSA-qfcc-2fm3-9q42)', function () {
    test('loadDestinations cannot access server from another team', function () {
        $component = Livewire::test(GlobalSearch::class)
            ->set('selectedServerId', $this->serverB->id)
            ->call('loadDestinations');

        // Should dispatch error because server is not found (team-scoped)
        $component->assertDispatched('error');
    });
});

describe('GlobalSearch Project IDOR (GHSA-qfcc-2fm3-9q42)', function () {
    test('loadEnvironments cannot access project from another team', function () {
        $component = Livewire::test(GlobalSearch::class)
            ->set('selectedProjectUuid', $this->projectB->uuid)
            ->call('loadEnvironments');

        // Should not load environments from another team's project
        expect($component->get('availableEnvironments'))->toBeEmpty();
    });
});

describe('DeleteProject IDOR (GHSA-qfcc-2fm3-9q42)', function () {
    test('cannot mount DeleteProject with project from another team', function () {
        // Should throw ModelNotFoundException (404) because team-scoped query won't find it
        Livewire::test(DeleteProject::class, ['project_id' => $this->projectB->id]);
    })->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    test('can mount DeleteProject with own team project', function () {
        $component = Livewire::test(DeleteProject::class, ['project_id' => $this->projectA->id]);

        expect($component->get('projectName'))->toBe($this->projectA->name);
    });
});

describe('CloneMe Project IDOR (GHSA-qfcc-2fm3-9q42)', function () {
    test('cannot mount CloneMe with project UUID from another team', function () {
        // Should throw ModelNotFoundException because team-scoped query won't find it
        Livewire::test(CloneMe::class, [
            'project_uuid' => $this->projectB->uuid,
            'environment_uuid' => $this->environmentB->uuid,
        ]);
    })->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    test('can mount CloneMe with own team project UUID', function () {
        $component = Livewire::test(CloneMe::class, [
            'project_uuid' => $this->projectA->uuid,
            'environment_uuid' => $this->environmentA->uuid,
        ]);

        expect($component->get('project_id'))->toBe($this->projectA->id);
    });
});

describe('DeployController API Server IDOR (GHSA-qfcc-2fm3-9q42)', function () {
    test('deploy cancel API cannot access build server from another team', function () {
        // Create a deployment queue entry that references Team B's server as build_server
        $application = \App\Models\Application::factory()->create([
            'environment_id' => $this->environmentA->id,
            'destination_id' => StandaloneDocker::factory()->create(['server_id' => $this->serverA->id])->id,
            'destination_type' => StandaloneDocker::class,
        ]);

        $deployment = \App\Models\ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'deployment_uuid' => 'test-deploy-' . fake()->uuid(),
            'server_id' => $this->serverA->id,
            'build_server_id' => $this->serverB->id, // Cross-team build server
            'status' => \App\Enums\ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        $token = $this->userA->createToken('test-token', ['*']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->deleteJson("/api/v1/deployments/{$deployment->deployment_uuid}");

        // The cancellation should proceed but the build_server should NOT be found
        // (team-scoped query returns null for Team B's server)
        // The deployment gets cancelled but no remote process runs on the wrong server
        $response->assertOk();

        // Verify the deployment was cancelled
        $deployment->refresh();
        expect($deployment->status)->toBe(
            \App\Enums\ApplicationDeploymentStatus::CANCELLED_BY_USER->value
        );
    });
});
