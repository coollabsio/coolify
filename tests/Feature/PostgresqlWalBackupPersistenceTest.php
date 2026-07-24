<?php

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
use Illuminate\Database\QueryException;
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

    $this->database = createPostgresqlWalPersistenceDatabase($this->team, 'source-postgres');
    $this->storage = createPostgresqlWalPersistenceStorage($this->team);
});

it('persists configuration defaults and relationships', function () {
    $configuration = PostgresqlWalBackupConfiguration::create([
        'team_id' => $this->team->id,
        'standalone_postgresql_id' => $this->database->id,
        's3_storage_id' => $this->storage->id,
        'postgres_major_version' => 16,
    ]);

    expect($configuration->uuid)->not->toBeEmpty()
        ->and($configuration->enabled)->toBeTrue()
        ->and($configuration->base_backup_frequency)->toBe('0 3 * * *')
        ->and($configuration->archive_timeout_seconds)->toBe(60)
        ->and($configuration->wal_level)->toBe('replica')
        ->and($configuration->retention_full_backups)->toBe(7)
        ->and($configuration->timeout)->toBe(3600)
        ->and($configuration->status)->toBe('warning')
        ->and($configuration->last_failed_count)->toBe(0)
        ->and($configuration->database->is($this->database))->toBeTrue()
        ->and($configuration->team->is($this->team))->toBeTrue()
        ->and($configuration->s3->is($this->storage))->toBeTrue()
        ->and($this->database->walBackupConfiguration->is($configuration))->toBeTrue()
        ->and($this->team->postgresqlWalBackupConfigurations->contains($configuration))->toBeTrue()
        ->and($this->storage->postgresqlWalBackupConfigurations->contains($configuration))->toBeTrue();
});

it('persists execution defaults and relationships', function () {
    $configuration = createPostgresqlWalPersistenceConfiguration($this->team, $this->database, $this->storage);
    $restoredDatabase = createPostgresqlWalPersistenceDatabase($this->team, 'restored-postgres');

    $execution = PostgresqlWalBackupExecution::create([
        'postgresql_wal_backup_configuration_id' => $configuration->id,
        'operation' => 'restore',
        'restored_database_id' => $restoredDatabase->id,
        'target_time' => now()->subMinute(),
    ]);

    expect($execution->uuid)->not->toBeEmpty()
        ->and($execution->status)->toBe('running')
        ->and($execution->started_at)->not->toBeNull()
        ->and($execution->target_time)->not->toBeNull()
        ->and($execution->configuration->is($configuration))->toBeTrue()
        ->and($execution->restoredDatabase->is($restoredDatabase))->toBeTrue()
        ->and($configuration->executions->contains($execution))->toBeTrue();
});

it('allows only one configuration per postgresql database', function () {
    createPostgresqlWalPersistenceConfiguration($this->team, $this->database, $this->storage);

    expect(fn () => createPostgresqlWalPersistenceConfiguration($this->team, $this->database, $this->storage))
        ->toThrow(QueryException::class);
});

it('cascades configurations and executions when the source database is force deleted', function () {
    $configuration = createPostgresqlWalPersistenceConfiguration($this->team, $this->database, $this->storage);
    $execution = $configuration->executions()->create(['operation' => 'base_backup']);

    $this->database->forceDelete();

    $this->assertDatabaseMissing('postgresql_wal_backup_configurations', ['id' => $configuration->id]);
    $this->assertDatabaseMissing('postgresql_wal_backup_executions', ['id' => $execution->id]);
});

it('cascades executions when a configuration is deleted', function () {
    $configuration = createPostgresqlWalPersistenceConfiguration($this->team, $this->database, $this->storage);
    $execution = $configuration->executions()->create(['operation' => 'health_check']);

    $configuration->delete();

    $this->assertDatabaseMissing('postgresql_wal_backup_executions', ['id' => $execution->id]);
});

it('nulls foreign keys when related storage or restored database is deleted', function () {
    $configuration = createPostgresqlWalPersistenceConfiguration($this->team, $this->database, $this->storage);
    $restoredDatabase = createPostgresqlWalPersistenceDatabase($this->team, 'restored-postgres');
    $execution = $configuration->executions()->create([
        'operation' => 'restore',
        'restored_database_id' => $restoredDatabase->id,
    ]);

    S3Storage::withoutEvents(fn () => $this->storage->delete());
    $restoredDatabase->forceDelete();

    expect($configuration->fresh()->s3_storage_id)->toBeNull()
        ->and($execution->fresh()->restored_database_id)->toBeNull();
});

it('disables and detaches a configuration when its storage is deleted', function () {
    $configuration = createPostgresqlWalPersistenceConfiguration($this->team, $this->database, $this->storage);

    $this->storage->delete();

    expect($configuration->fresh()->enabled)->toBeFalse()
        ->and($configuration->fresh()->status)->toBe('failed')
        ->and($configuration->fresh()->s3_storage_id)->toBeNull();
});

it('warns about PITR configurations before deleting an S3 storage', function () {
    createPostgresqlWalPersistenceConfiguration($this->team, $this->database, $this->storage);

    $this->get(route('storage.show', ['storage_uuid' => $this->storage->uuid]))
        ->assertOk()
        ->assertSee('1 PITR configuration(s) will be detached and require attention. Existing objects in this storage will not be deleted.');
});

function createPostgresqlWalPersistenceConfiguration(
    Team $team,
    StandalonePostgresql $database,
    S3Storage $storage,
): PostgresqlWalBackupConfiguration {
    return PostgresqlWalBackupConfiguration::create([
        'team_id' => $team->id,
        'standalone_postgresql_id' => $database->id,
        's3_storage_id' => $storage->id,
        'postgres_major_version' => 16,
    ]);
}

function createPostgresqlWalPersistenceDatabase(Team $team, string $name): StandalonePostgresql
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return StandalonePostgresql::create([
        'name' => $name,
        'image' => 'ghcr.io/coollabsio/postgres-walg:16',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function createPostgresqlWalPersistenceStorage(Team $team): S3Storage
{
    return S3Storage::create([
        'name' => 'PostgreSQL WAL archive',
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => $team->id,
    ]);
}
