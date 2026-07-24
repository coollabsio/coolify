<?php

use App\Actions\Database\RestartDatabase;
use App\Jobs\PostgresqlWalBaseBackupJob;
use App\Jobs\PostgresqlWalHealthCheckJob;
use App\Jobs\PostgresqlWalRestoreJob;
use App\Livewire\Project\Database\PointInTimeRecovery;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PostgresqlWalBackupConfiguration;
use App\Models\PostgresqlWalBackupExecution;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => 'owner']);
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member, ['role' => 'member']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->database = createPostgresqlPitrPageDatabase($this->environment, $this->destination, 'pitr-postgres');
    $this->storage = createPostgresqlPitrPageStorage($this->team, 'primary');
    $this->configuration = PostgresqlWalBackupConfiguration::create([
        'team_id' => $this->team->id,
        'standalone_postgresql_id' => $this->database->id,
        's3_storage_id' => $this->storage->id,
        'enabled' => true,
        'postgres_major_version' => 16,
        'status' => 'warning',
    ]);
    PostgresqlWalBackupExecution::create([
        'postgresql_wal_backup_configuration_id' => $this->configuration->id,
        'operation' => 'health_check',
        'status' => 'success',
        'message' => 'WAL archiving is healthy.',
        'finished_at' => now(),
    ]);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
});

afterEach(function () {
    RestartDatabase::clearFake();
});

it('shows the PITR page and navigation only for PITR PostgreSQL databases', function () {
    $pitrUrl = postgresqlPitrPageUrl($this, $this->database);

    $this->get($pitrUrl)
        ->assertSuccessful()
        ->assertSeeText('Point-in-Time Recovery')
        ->assertSeeText('Run Base Backup Now');
    $this->get(postgresqlDatabaseConfigurationUrl($this, $this->database))
        ->assertSuccessful()
        ->assertSeeText('Point-in-Time Recovery');
    $this->get(postgresqlDatabaseDangerUrl($this, $this->database))
        ->assertSuccessful()
        ->assertSeeText('WAL-G backups are retained')
        ->assertSeeText("s3://{$this->storage->bucket}/coolify/postgresql/{$this->database->uuid}/pg16");

    $regularDatabase = createPostgresqlPitrPageDatabase($this->environment, $this->destination, 'regular-postgres', 'postgres:16-alpine');
    $this->get(postgresqlPitrPageUrl($this, $regularDatabase))->assertNotFound();
    $this->get(postgresqlDatabaseConfigurationUrl($this, $regularDatabase))
        ->assertSuccessful()
        ->assertDontSeeText('Point-in-Time Recovery');
});

it('allows team members to view PITR status but not mutate settings', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $this->get(postgresqlPitrPageUrl($this, $this->database))->assertSuccessful();

    Livewire::actingAs($this->member)
        ->test(PointInTimeRecovery::class, ['database' => $this->database])
        ->set('baseBackupFrequency', 'hourly')
        ->call('save')
        ->assertForbidden();

    expect($this->configuration->fresh()->base_backup_frequency)->toBe('0 3 * * *');
});

it('saves PITR settings and marks the configuration pending restart', function () {
    Livewire::test(PointInTimeRecovery::class, ['database' => $this->database])
        ->set('baseBackupFrequency', 'hourly')
        ->set('archiveTimeoutSeconds', 120)
        ->set('walLevel', 'logical')
        ->set('retentionFullBackups', 5)
        ->set('timeout', 7200)
        ->call('save')
        ->assertHasNoErrors();

    $configuration = $this->configuration->fresh();
    expect($configuration->base_backup_frequency)->toBe('hourly')
        ->and($configuration->archive_timeout_seconds)->toBe(120)
        ->and($configuration->wal_level)->toBe('logical')
        ->and($configuration->retention_full_backups)->toBe(5)
        ->and($configuration->timeout)->toBe(7200)
        ->and($configuration->status)->toBe('pending_restart');
});

it('allows detached storage reattachment but rejects active storage swaps', function () {
    $replacement = createPostgresqlPitrPageStorage($this->team, 'replacement');

    Livewire::test(PointInTimeRecovery::class, ['database' => $this->database])
        ->set('s3StorageUuid', $replacement->uuid)
        ->call('save')
        ->assertHasErrors('s3StorageUuid');

    expect($this->configuration->fresh()->s3_storage_id)->toBe($this->storage->id);

    $this->configuration->update([
        's3_storage_id' => null,
        'enabled' => false,
        'status' => 'failed',
        'last_base_backup_at' => now()->subHours(2),
        'last_successful_base_backup_at' => now()->subHour(),
    ]);
    $foreignTeam = Team::factory()->create();
    $foreignStorage = createPostgresqlPitrPageStorage($foreignTeam, 'foreign');

    Livewire::test(PointInTimeRecovery::class, ['database' => $this->database])
        ->set('s3StorageUuid', $foreignStorage->uuid)
        ->call('save')
        ->assertHasErrors('s3StorageUuid');

    Livewire::test(PointInTimeRecovery::class, ['database' => $this->database])
        ->set('s3StorageUuid', $replacement->uuid)
        ->call('save')
        ->assertHasNoErrors();

    $configuration = $this->configuration->fresh();
    expect($configuration->s3_storage_id)->toBe($replacement->id)
        ->and($configuration->enabled)->toBeTrue()
        ->and($configuration->status)->toBe('pending_restart')
        ->and($configuration->last_base_backup_at)->toBeNull()
        ->and($configuration->last_successful_base_backup_at)->toBeNull();
});

it('queues manual base backups, health checks, and UTC restores', function () {
    Queue::fake();

    Livewire::test(PointInTimeRecovery::class, ['database' => $this->database])
        ->call('runBaseBackup')
        ->call('runHealthCheck')
        ->set('restoreTargetTime', now()->subMinute()->utc()->toIso8601ZuluString())
        ->set('restoreName', 'restored-postgres')
        ->call('restore')
        ->assertHasNoErrors();

    Queue::assertPushed(
        PostgresqlWalBaseBackupJob::class,
        fn (PostgresqlWalBaseBackupJob $job) => $job->configuration->is($this->configuration)
            && $job->retryWhenBusy,
    );
    Queue::assertPushed(PostgresqlWalHealthCheckJob::class);
    Queue::assertPushed(
        PostgresqlWalRestoreJob::class,
        fn (PostgresqlWalRestoreJob $job) => $job->sourceConfiguration->is($this->configuration)
            && $job->name === 'restored-postgres',
    );
});

it('rejects non-UTC and future restore targets', function (string $targetTime) {
    Queue::fake();

    Livewire::test(PointInTimeRecovery::class, ['database' => $this->database])
        ->set('restoreTargetTime', $targetTime)
        ->set('restoreName', 'restored-postgres')
        ->call('restore')
        ->assertHasErrors('restoreTargetTime');

    Queue::assertNotPushed(PostgresqlWalRestoreJob::class);
})->with([
    'missing UTC offset' => fn () => now()->subMinute()->format('Y-m-d\TH:i:s'),
    'future target' => fn () => now()->addMinute()->utc()->toIso8601ZuluString(),
]);

it('applies settings through the database restart action', function () {
    RestartDatabase::shouldRun()
        ->once()
        ->withArgs(fn (StandalonePostgresql $database) => $database->is($this->database))
        ->andReturn((object) ['id' => 'activity-id']);

    Livewire::test(PointInTimeRecovery::class, ['database' => $this->database])
        ->call('applyAndRestart')
        ->assertDispatched('activityMonitor')
        ->assertHasNoErrors();

    expect($this->configuration->fresh()->status)->toBe('pending_restart');
});

function postgresqlPitrPageUrl(object $test, StandalonePostgresql $database): string
{
    return route('project.database.pitr', [
        'project_uuid' => $test->project->uuid,
        'environment_uuid' => $test->environment->uuid,
        'database_uuid' => $database->uuid,
    ]);
}

function postgresqlDatabaseConfigurationUrl(object $test, StandalonePostgresql $database): string
{
    return route('project.database.configuration', [
        'project_uuid' => $test->project->uuid,
        'environment_uuid' => $test->environment->uuid,
        'database_uuid' => $database->uuid,
    ]);
}

function postgresqlDatabaseDangerUrl(object $test, StandalonePostgresql $database): string
{
    return route('project.database.danger', [
        'project_uuid' => $test->project->uuid,
        'environment_uuid' => $test->environment->uuid,
        'database_uuid' => $database->uuid,
    ]);
}

function createPostgresqlPitrPageDatabase(
    Environment $environment,
    StandaloneDocker $destination,
    string $name,
    string $image = 'ghcr.io/coollabsio/postgres-walg:16',
): StandalonePostgresql {
    return StandalonePostgresql::create([
        'name' => $name,
        'image' => $image,
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'status' => 'running:healthy',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function createPostgresqlPitrPageStorage(Team $team, string $name): S3Storage
{
    return S3Storage::create([
        'team_id' => $team->id,
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket-'.$name,
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
    ]);
}
