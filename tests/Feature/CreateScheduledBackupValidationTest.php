<?php

use App\Livewire\Project\Database\CreateScheduledBackup;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

it('creates a standalone database backup without S3 and opens its configuration', function () {
    $database = StandalonePostgresql::create([
        'name' => 'postgres',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $component = Livewire::test(CreateScheduledBackup::class, ['database' => $database])
        ->assertDontSee('Save to S3')
        ->set('frequency', 'daily')
        ->call('submit');

    $backup = ScheduledDatabaseBackup::query()->sole();

    $component->assertRedirectToRoute('project.database.backup.execution', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'database_uuid' => $database->uuid,
        'backup_uuid' => $backup->uuid,
    ]);

    expect($backup->save_s3)->toBeFalsy()
        ->and($backup->s3_storage_id)->toBeNull();
});

it('creates a service database backup without S3 and opens its configuration', function () {
    $service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
    ]);
    $database = ServiceDatabase::create([
        'service_id' => $service->id,
        'name' => 'postgres',
        'image' => 'postgres:16-alpine',
        'custom_type' => 'postgresql',
    ]);

    $component = Livewire::test(CreateScheduledBackup::class, ['database' => $database])
        ->assertDontSee('Save to S3')
        ->set('frequency', 'daily')
        ->call('submit');

    $backup = ScheduledDatabaseBackup::query()->sole();

    $component->assertRedirectToRoute('project.service.database.backup.show', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'service_uuid' => $service->uuid,
        'stack_service_uuid' => $database->uuid,
        'backup_uuid' => $backup->uuid,
    ]);

    expect($backup->save_s3)->toBeFalsy()
        ->and($backup->s3_storage_id)->toBeNull();
});

it('selects a service database when creating a backup from the unified backups page', function () {
    $service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
    ]);
    ServiceDatabase::create([
        'service_id' => $service->id,
        'name' => 'primary',
        'image' => 'postgres:16-alpine',
        'custom_type' => 'postgresql',
    ]);
    $analytics = ServiceDatabase::create([
        'service_id' => $service->id,
        'name' => 'analytics',
        'image' => 'postgres:16-alpine',
        'custom_type' => 'postgresql',
    ]);

    Livewire::test(CreateScheduledBackup::class, ['service' => $service])
        ->assertSee('Database')
        ->assertSee('analytics')
        ->set('selectedDatabaseUuid', $analytics->uuid)
        ->set('frequency', 'daily')
        ->call('submit');

    expect(ScheduledDatabaseBackup::query()->sole()->database->is($analytics))->toBeTrue();
});

it('creates a clickhouse backup for its configured database', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $database = StandaloneClickhouse::create([
        'name' => 'clickhouse-scheduled-backup',
        'clickhouse_admin_user' => 'default',
        'clickhouse_admin_password' => 'password',
        'clickhouse_db' => 'analytics',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $component = Livewire::test(CreateScheduledBackup::class, ['database' => $database])
        ->set('frequency', 'daily')
        ->call('submit');

    $backup = ScheduledDatabaseBackup::firstOrFail();

    $component->assertRedirectToRoute('project.database.backup.execution', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'database_uuid' => $database->uuid,
        'backup_uuid' => $backup->uuid,
    ]);

    expect($backup->database_type)->toBe(StandaloneClickhouse::class)
        ->and($backup->databases_to_backup)->toBe('analytics');
});

it('rejects scheduled backups for unsupported database types', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $database = StandaloneRedis::create([
        'name' => 'redis-without-backups',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    Livewire::test(CreateScheduledBackup::class, ['database' => $database])
        ->set('frequency', 'daily')
        ->call('submit')
        ->assertDispatched('error');

    expect(ScheduledDatabaseBackup::count())->toBe(0);
});
