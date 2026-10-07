<?php

use App\Jobs\DatabaseBackupJob;
use App\Livewire\Project\Database\CreateScheduledBackup;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\StandaloneSqlite;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
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

    $component->assertRedirectToRoute('project.service.volume-backups.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'service_uuid' => $service->uuid,
        'backup_uuid' => $backup->uuid,
    ]);

    expect($backup->save_s3)->toBeFalsy()
        ->and($backup->s3_storage_id)->toBeNull();
});

it('rejects an unsupported service database name during backup', function () {
    $service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
    ]);
    $database = ServiceDatabase::create([
        'service_id' => $service->id,
        'name' => 'postgres test',
        'image' => 'postgres:16-alpine',
        'custom_type' => 'postgresql',
        'status' => 'running',
    ]);
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => ServiceDatabase::class,
        'database_id' => $database->id,
        'team_id' => $this->team->id,
    ]);

    expect(fn () => (new DatabaseBackupJob($backup))->handle())
        ->toThrow(Exception::class, 'Invalid database container name.');
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

    $component = Livewire::test(CreateScheduledBackup::class, ['service' => $service])
        ->assertSee('Database')
        ->assertSee('analytics')
        ->set('selectedDatabaseUuid', $analytics->uuid)
        ->set('frequency', 'daily')
        ->call('submit');

    $backup = ScheduledDatabaseBackup::query()->sole();
    expect($backup->database->is($analytics))->toBeTrue();

    $component->assertRedirectToRoute('project.service.volume-backups.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'service_uuid' => $service->uuid,
        'backup_uuid' => $backup->uuid,
    ]);
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

it('creates a sqlite backup for its configured database files', function () {
    $database = create_standalone_sqlite($this->environment->id, $this->destination, ['sqlite_databases' => 'app.db,jobs.db']);

    Livewire::test(CreateScheduledBackup::class, ['database' => $database])
        ->set('frequency', 'daily')
        ->call('submit');

    $backup = ScheduledDatabaseBackup::firstOrFail();

    expect($backup->database_type)->toBe(StandaloneSqlite::class)
        ->and($backup->databases_to_backup)->toBeNull();
});

it('backs up sqlite files added to the database after the backup was scheduled', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Notification::fake();
    $this->server->update(['private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $database = create_standalone_sqlite($this->environment->id, $this->destination, [
        'sqlite_databases' => 'app.db',
        'status' => 'running:healthy',
    ]);

    Livewire::test(CreateScheduledBackup::class, ['database' => $database])
        ->set('frequency', 'daily')
        ->call('submit');

    $database->update(['sqlite_databases' => 'app.db,audit.db']);

    $commands = collect();
    Process::fake(function ($process) use ($commands) {
        $commands->push(is_array($process->command) ? implode(' ', $process->command) : $process->command);

        return Process::result(output: '');
    });

    try {
        (new DatabaseBackupJob(ScheduledDatabaseBackup::query()->sole()))->handle();
    } catch (Throwable) {
        // Faked remote commands return no backup size; only the files the job dumped matter here.
    }

    $dumpedFiles = $commands->filter(fn (string $command): bool => str_contains($command, 'VACUUM INTO'))->implode("\n");
    expect($dumpedFiles)->toContain('/var/lib/sqlite/app.db')
        ->toContain('/var/lib/sqlite/audit.db');
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

it('assigns a new database backup schedule to the team of the database, not the current team', function (string $databaseType, bool $rootTeam) {
    if ($rootTeam) {
        $rootTeamModel = Team::factory()->create(['id' => 0]);
        $this->user->teams()->attach($rootTeamModel, ['role' => 'owner']);
        $this->project->update(['team_id' => 0]);
    }
    $resourceTeamId = $this->project->fresh()->team_id;
    $otherTeam = Team::factory()->create();
    $this->user->teams()->attach($otherTeam, ['role' => 'owner']);
    session(['currentTeam' => $otherTeam]);

    if ($databaseType === 'service') {
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
    } else {
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
    }

    Livewire::test(CreateScheduledBackup::class, ['database' => $database])
        ->set('frequency', 'daily')
        ->call('submit')
        ->assertHasNoErrors();

    expect(ScheduledDatabaseBackup::query()->sole()->team_id)->toBe($resourceTeamId);
})->with([
    'standalone database' => ['standalone', false],
    'service database' => ['service', false],
    'standalone database in the root team' => ['standalone', true],
]);
