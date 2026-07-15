<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('renders frontend-only application backup search data for volume names and frequencies', function () {
    $application = createBackupSearchApplication($this->team);
    $dailyVolume = createBackupSearchVolume($application, 'app-data');
    $weeklyVolume = createBackupSearchVolume($application, 'Cache-Data');

    $dailyVolume->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'frequency' => 'daily',
    ]);
    $weeklyVolume->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'frequency' => '0 4 * * 0',
    ]);

    $parameters = [
        'project_uuid' => $application->project()->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
    ];

    $this->get(route('project.application.backup.index', [...$parameters, 'search' => 'cache']))
        ->assertOk()
        ->assertSee('Cache-Data')
        ->assertSee('Volume: app-data')
        ->assertSee("search: 'cache'", false)
        ->assertSee('x-model="search"', false)
        ->assertSee('x-show=', false)
        ->assertSee('No scheduled backups match your search.');
});

it('renders frontend-only database backup search data for database names and frequencies', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $database = StandalonePostgresql::create([
        'name' => 'Orders-Primary',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $database->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'frequency' => 'daily',
    ]);
    $database->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'frequency' => '0 3 * * 1',
    ]);

    $parameters = [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'database_uuid' => $database->uuid,
    ];

    $this->get(route('project.database.backup.index', [...$parameters, 'search' => '0 3']))
        ->assertOk()
        ->assertSee('<h3 class="font-semibold">0 3 * * 1</h3>', false)
        ->assertSee('<h3 class="font-semibold">daily</h3>', false)
        ->assertSee('x-model="search"', false)
        ->assertSee('x-show=', false)
        ->assertSee('No scheduled backups match your search.');
});

function createBackupSearchApplication(Team $team): Application
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    return $application;
}

function createBackupSearchVolume(Application $application, string $name): LocalPersistentVolume
{
    return LocalPersistentVolume::create([
        'name' => $name,
        'mount_path' => '/'.str($name)->lower(),
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
}
