<?php

use App\Livewire\Project\Shared\ResourceOperations;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);

    // Team A (attacker's team)
    $this->userA = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->userA->teams()->attach($this->teamA, ['role' => 'owner']);

    $this->serverA = Server::factory()->create(['team_id' => $this->teamA->id]);
    $this->destinationA = StandaloneDocker::where('server_id', $this->serverA->id)->firstOrFail();
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);

    $this->applicationA = Application::factory()->create([
        'environment_id' => $this->environmentA->id,
        'destination_id' => $this->destinationA->id,
        'destination_type' => $this->destinationA->getMorphClass(),
    ]);

    // Team B (victim's team)
    $this->teamB = Team::factory()->create();
    $this->serverB = Server::factory()->create(['team_id' => $this->teamB->id]);
    $this->destinationB = StandaloneDocker::where('server_id', $this->serverB->id)->firstOrFail();
    $this->projectB = Project::factory()->create(['team_id' => $this->teamB->id]);
    $this->environmentB = Environment::factory()->create(['project_id' => $this->projectB->id]);

    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);
});

test('cloneTo rejects destination belonging to another team', function () {
    Livewire::test(ResourceOperations::class, ['resource' => $this->applicationA])
        ->call('cloneTo', $this->destinationB->uuid)
        ->assertHasErrors('destination_id');

    // Ensure no cross-tenant application was created
    expect(Application::where('destination_id', $this->destinationB->id)->exists())->toBeFalse();
});

test('cloneTo allows destination belonging to own team', function () {
    $secondDestination = StandaloneDocker::factory()->create([
        'server_id' => $this->serverA->id,
        'network' => 'second-destination',
    ]);

    Livewire::test(ResourceOperations::class, ['resource' => $this->applicationA])
        ->call('cloneTo', $secondDestination->uuid)
        ->assertHasNoErrors('destination_id');

    expect(Application::count())->toBe(2);
});

test('cloneTo can place the cloned resource in another environment', function () {
    $targetEnvironment = Environment::factory()->create(['project_id' => $this->projectA->id]);

    Livewire::test(ResourceOperations::class, ['resource' => $this->applicationA])
        ->call('cloneTo', $this->destinationA->uuid, $targetEnvironment->id)
        ->assertRedirect();

    $clone = Application::whereKeyNot($this->applicationA->id)->firstOrFail();

    expect($clone->environment_id)->toBe($targetEnvironment->id);
});

test('cloneTo rejects an environment belonging to another team', function () {
    Livewire::test(ResourceOperations::class, ['resource' => $this->applicationA])
        ->call('cloneTo', $this->destinationA->uuid, $this->environmentB->id)
        ->assertHasErrors('environment_id');

    expect(Application::count())->toBe(1);
});

test('moveTo rejects environment belonging to another team', function () {
    Livewire::test(ResourceOperations::class, ['resource' => $this->applicationA])
        ->call('moveTo', $this->environmentB->id);

    // Resource should still be in original environment
    $this->applicationA->refresh();
    expect($this->applicationA->environment_id)->toBe($this->environmentA->id);
});

test('moveTo allows environment belonging to own team', function () {
    $secondEnvironment = Environment::factory()->create(['project_id' => $this->projectA->id]);

    Livewire::test(ResourceOperations::class, ['resource' => $this->applicationA])
        ->call('moveTo', $secondEnvironment->id)
        ->assertRedirect();

    $this->applicationA->refresh();
    expect($this->applicationA->environment_id)->toBe($secondEnvironment->id);
});

test('StandaloneDockerPolicy denies update for cross-team user', function () {
    expect($this->userA->can('update', $this->destinationB))->toBeFalse();
});

test('StandaloneDockerPolicy allows update for same-team user', function () {
    expect($this->userA->can('update', $this->destinationA))->toBeTrue();
});

describe('after the session team changes', function () {
    beforeEach(function () {
        Server::flushIdentityMap();
        $this->userA->teams()->attach($this->teamB, ['role' => 'owner']);
        $this->userA->unsetRelation('teams');
        $this->secondEnvironmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);
    });

    function resourceOperationsAfterTeamSwitch(object $test, $resource): Testable
    {
        $component = Livewire::test(ResourceOperations::class, ['resource' => $resource]);
        session(['currentTeam' => $test->teamB]);

        return $component;
    }

    test('lists the projects and servers of the resource team', function () {
        session(['currentTeam' => $this->teamB]);

        $component = Livewire::test(ResourceOperations::class, ['resource' => $this->applicationA]);

        expect($component->get('projects')->pluck('id')->all())->toBe([$this->projectA->id])
            ->and($component->get('servers')->pluck('id')->all())->toBe([$this->serverA->id]);
    });

    test('cloneTo rejects a destination of the session team', function () {
        resourceOperationsAfterTeamSwitch($this, $this->applicationA)
            ->call('cloneTo', $this->destinationB->uuid)
            ->assertHasErrors('destination_id');

        expect(Application::count())->toBe(1);
    });

    test('cloneTo rejects an environment and destination of another team', function () {
        resourceOperationsAfterTeamSwitch($this, $this->applicationA)
            ->call('cloneTo', $this->destinationB->uuid, $this->environmentB->id)
            ->assertHasErrors('environment_id');

        expect(Application::count())->toBe(1);
    });

    test('moveTo rejects an environment of the session team', function () {
        resourceOperationsAfterTeamSwitch($this, $this->applicationA)
            ->call('moveTo', $this->environmentB->id);

        expect($this->applicationA->fresh()->environment_id)->toBe($this->environmentA->id);
    });

    test('migrateTo rejects a destination of the session team', function () {
        resourceOperationsAfterTeamSwitch($this, $this->applicationA)
            ->call('migrateTo', $this->destinationB->uuid)
            ->assertHasErrors(['destination_id' => 'Destination not found.']);
    });

    test('cloneTo and moveTo still work inside the resource team', function () {
        resourceOperationsAfterTeamSwitch($this, $this->applicationA)
            ->call('cloneTo', $this->destinationA->uuid, $this->secondEnvironmentA->id)
            ->assertHasNoErrors()
            ->assertRedirect();

        $clone = Application::whereKeyNot($this->applicationA->id)->sole();
        expect($clone->environment_id)->toBe($this->secondEnvironmentA->id)
            ->and($clone->destination_id)->toBe($this->destinationA->id);

        resourceOperationsAfterTeamSwitch($this, $this->applicationA)
            ->call('moveTo', $this->secondEnvironmentA->id)
            ->assertRedirect();

        expect($this->applicationA->fresh()->environment_id)->toBe($this->secondEnvironmentA->id);
    });

    test('cloned application tasks belong to the resource team', function () {
        ScheduledTask::factory()->create(['application_id' => $this->applicationA->id, 'team_id' => $this->teamA->id]);

        resourceOperationsAfterTeamSwitch($this, $this->applicationA)
            ->call('cloneTo', $this->destinationA->uuid)
            ->assertRedirect();

        $clone = Application::whereKeyNot($this->applicationA->id)->sole();
        expect($clone->scheduled_tasks()->sole()->team_id)->toBe($this->teamA->id);
    });

    test('cloned database backups belong to the resource team', function () {
        $database = StandalonePostgresql::create([
            'name' => 'pg', 'uuid' => new_public_id(), 'postgres_password' => 'secret', 'postgres_user' => 'postgres',
            'postgres_db' => 'postgres', 'environment_id' => $this->environmentA->id, 'status' => 'exited',
            'destination_id' => $this->destinationA->id, 'destination_type' => $this->destinationA->getMorphClass(),
        ]);
        ScheduledDatabaseBackup::create([
            'frequency' => '0 0 * * *', 'database_type' => $database->getMorphClass(), 'database_id' => $database->id,
            'team_id' => $this->teamA->id,
        ]);

        resourceOperationsAfterTeamSwitch($this, $database)
            ->call('cloneTo', $this->destinationA->uuid)
            ->assertRedirect();

        $clone = StandalonePostgresql::whereKeyNot($database->id)->sole();
        expect($clone->scheduledBackups()->sole()->team_id)->toBe($this->teamA->id);
    });

    test('cloned service tasks belong to the resource team', function () {
        $service = Service::factory()->create([
            'environment_id' => $this->environmentA->id, 'server_id' => $this->serverA->id,
            'destination_id' => $this->destinationA->id, 'destination_type' => $this->destinationA->getMorphClass(),
            'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        ]);
        ScheduledTask::factory()->create(['service_id' => $service->id, 'team_id' => $this->teamA->id]);

        resourceOperationsAfterTeamSwitch($this, $service)
            ->call('cloneTo', $this->destinationA->uuid)
            ->assertRedirect();

        $clone = Service::whereKeyNot($service->id)->sole();
        expect($clone->scheduled_tasks()->sole()->team_id)->toBe($this->teamA->id);
    });

    test('a member of the resource team cannot clone or move after switching to an own team', function () {
        $member = User::factory()->create();
        $member->teams()->attach($this->teamA, ['role' => 'member']);
        $member->teams()->attach($this->teamB, ['role' => 'owner']);
        $this->actingAs($member);

        resourceOperationsAfterTeamSwitch($this, $this->applicationA)
            ->call('cloneTo', $this->destinationA->uuid)
            ->call('moveTo', $this->secondEnvironmentA->id);

        expect(Application::count())->toBe(1)
            ->and($this->applicationA->fresh()->environment_id)->toBe($this->environmentA->id);
    });

    test('clone_application rejects a destination outside the environment team', function () {
        session(['currentTeam' => $this->teamB]);

        expect(fn () => clone_application($this->applicationA, $this->destinationB, ['environment_id' => $this->environmentA->id]))
            ->toThrow(RuntimeException::class, 'same team');
        expect(Application::count())->toBe(1);
    });
});
