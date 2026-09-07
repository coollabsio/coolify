<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\S3Storage;
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
        ->assertSee('app-data')
        ->assertSee("search: 'cache'", false)
        ->assertSee('x-model="search"', false)
        ->assertSee('x-show=', false)
        ->assertSee('No scheduled backups match your search.')
        ->assertSee('Filter')
        ->assertSee('Sort')
        ->assertSee('All targets')
        ->assertSee('Target A–Z')
        ->assertSee('filterOpen')
        ->assertSee('sortOpen')
        ->assertDontSee('class="font-mono text-xs">daily', false)
        ->assertDontSee('backup-type-filter-trigger', false)
        ->assertDontSee('backup-sort-trigger', false);

    expect(file_get_contents(resource_path('views/livewire/project/application/backup/index.blade.php')))
        ->toContain('wire:key="application-heading-backup-index-{{ $application->id }}"');
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
    $storage = S3Storage::create([
        'name' => 'Archive-Bucket',
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => $this->team->id,
    ]);
    $database->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'frequency' => 'daily',
        'save_s3' => true,
        's3_storage_id' => $storage->id,
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
        ->assertSee('0 3 * * 1')
        ->assertSee('daily')
        ->assertSee('Archive-Bucket')
        ->assertSee('archive-bucket', false)
        ->assertSee('backup.s3_storage.includes(query)', false)
        ->assertSee('Search backup schedules')
        ->assertSee('x-model="search"', false)
        ->assertSee('x-show=', false)
        ->assertSee('No matching backup schedules');
});

it('renders unavailable S3 storage only for S3-enabled database backups', function () {
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
        'save_s3' => true,
        's3_storage_id' => null,
    ]);
    $database->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'frequency' => 'weekly',
        'save_s3' => false,
        's3_storage_id' => null,
    ]);

    $parameters = [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'database_uuid' => $database->uuid,
    ];

    $response = $this->get(route('project.database.backup.index', $parameters))
        ->assertOk()
        ->assertSee('Unavailable')
        ->assertSee('Local only');

    // Only the S3-enabled schedule without a linked storage shows "Unavailable".
    expect(substr_count($response->getContent(), 'Unavailable'))->toBe(1);
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
