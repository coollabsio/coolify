<?php

use App\Livewire\Project\CloneMe;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
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
    Server::flushIdentityMap();

    $this->user = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->user->teams()->attach($this->teamA, ['role' => 'admin']);
    $this->user->teams()->attach($this->teamB, ['role' => 'owner']);

    $this->serverA = Server::factory()->create(['team_id' => $this->teamA->id]);
    $this->destinationA = StandaloneDocker::where('server_id', $this->serverA->id)->firstOrFail();
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);
    $this->applicationA = Application::factory()->create([
        'environment_id' => $this->environmentA->id,
        'destination_id' => $this->destinationA->id,
        'destination_type' => $this->destinationA->getMorphClass(),
    ]);

    $this->serverB = Server::factory()->create(['team_id' => $this->teamB->id]);
    $this->destinationB = StandaloneDocker::where('server_id', $this->serverB->id)->firstOrFail();

    $this->actingAs($this->user);
    session(['currentTeam' => $this->teamA]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function cloneMeAfterTeamSwitch(object $test): Testable
{
    $component = Livewire::test(CloneMe::class, [
        'project_uuid' => $test->projectA->uuid,
        'environment_uuid' => $test->environmentA->uuid,
    ]);
    session(['currentTeam' => $test->teamB]);

    return $component;
}

test('lists only servers of the project team', function () {
    session(['currentTeam' => $this->teamA]);

    $component = Livewire::test(CloneMe::class, [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
    ]);

    expect($component->get('servers')->pluck('id')->all())->toBe([$this->serverA->id]);
});

test('project clone with a session team destination creates nothing after a team switch', function () {
    $projectCount = Project::count();
    $environmentCount = Environment::count();

    cloneMeAfterTeamSwitch($this)
        ->set('selectedDestination', $this->destinationB->uuid)
        ->set('newName', 'zz-cross-team-clone')
        ->call('clone', 'project')
        ->assertDispatched('error');

    expect(Project::count())->toBe($projectCount)
        ->and(Environment::count())->toBe($environmentCount)
        ->and(Application::count())->toBe(1);
});

test('environment clone with a session team destination creates nothing after a team switch', function () {
    $environmentCount = Environment::count();

    cloneMeAfterTeamSwitch($this)
        ->set('selectedDestination', $this->destinationB->uuid)
        ->set('newName', 'zz-cross-team-env')
        ->call('clone', 'environment')
        ->assertDispatched('error');

    expect(Environment::count())->toBe($environmentCount)
        ->and(Application::count())->toBe(1);
});

test('a member of the project team cannot clone after switching to a team they own', function () {
    $this->user->teams()->updateExistingPivot($this->teamA->id, ['role' => 'member']);
    $this->user->unsetRelation('teams');
    $projectCount = Project::count();

    cloneMeAfterTeamSwitch($this)
        ->set('selectedDestination', $this->destinationA->uuid)
        ->set('newName', 'zz-member-clone')
        ->call('clone', 'project')
        ->assertDispatched('error');

    expect(Project::count())->toBe($projectCount)
        ->and(Application::count())->toBe(1);
});

test('project clone after a team switch stays in the source project team', function () {
    $database = StandalonePostgresql::create([
        'name' => 'pg-clone',
        'uuid' => new_public_id(),
        'postgres_password' => 'secret',
        'postgres_user' => 'postgres',
        'postgres_db' => 'postgres',
        'environment_id' => $this->environmentA->id,
        'destination_id' => $this->destinationA->id,
        'destination_type' => $this->destinationA->getMorphClass(),
        'status' => 'exited',
    ]);
    ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *', 'database_type' => $database->getMorphClass(), 'database_id' => $database->id,
        'team_id' => $this->teamA->id,
    ]);

    cloneMeAfterTeamSwitch($this)
        ->set('selectedDestination', $this->destinationA->uuid)
        ->set('newName', 'zz-same-team-clone')
        ->call('clone', 'project')
        ->assertNotDispatched('error')
        ->assertRedirect();

    $project = Project::where('name', 'zz-same-team-clone')->sole();
    $environment = $project->environments()->where('name', $this->environmentA->name)->sole();
    $clonedDatabase = StandalonePostgresql::where('environment_id', $environment->id)->sole();

    expect($project->team_id)->toBe($this->teamA->id)
        ->and($environment->applications()->count())->toBe(1)
        ->and($clonedDatabase->scheduledBackups()->sole()->team_id)->toBe($this->teamA->id);
});

test('a failing clone step leaves no partial project behind', function () {
    StandalonePostgresql::create([
        'name' => 'pg-fails',
        'uuid' => new_public_id(),
        'postgres_password' => 'secret',
        'postgres_user' => 'postgres',
        'postgres_db' => 'postgres',
        'environment_id' => $this->environmentA->id,
        'destination_id' => $this->destinationA->id,
        'destination_type' => $this->destinationA->getMorphClass(),
        'status' => 'exited',
    ]);
    StandalonePostgresql::created(fn () => throw new RuntimeException('Database clone failed.'));
    $projectCount = Project::count();
    $environmentCount = Environment::count();

    Livewire::test(CloneMe::class, [
        'project_uuid' => $this->projectA->uuid,
        'environment_uuid' => $this->environmentA->uuid,
    ])
        ->set('selectedDestination', $this->destinationA->uuid)
        ->set('newName', 'zz-failing-clone')
        ->call('clone', 'project')
        ->assertDispatched('error');

    expect(Project::count())->toBe($projectCount)
        ->and(Environment::count())->toBe($environmentCount)
        ->and(Application::count())->toBe(1);
});
