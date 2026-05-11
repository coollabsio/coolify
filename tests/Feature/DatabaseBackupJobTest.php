<?php

use App\Jobs\DatabaseBackupJob;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('pgbackrest default image is digest pinned', function () {
    $image = config('constants.coolify.pgbackrest_image');

    expect($image)->toContain('@sha256:');
    expect($image)->not->toEndWith(':latest');
});

test('scheduled_database_backup_executions table has s3_uploaded column', function () {
    expect(Schema::hasColumn('scheduled_database_backup_executions', 's3_uploaded'))->toBeTrue();
});

test('scheduled_database_backups table has pgbackrest configuration columns', function () {
    expect(Schema::hasColumn('scheduled_database_backups', 'backup_method'))->toBeTrue();
    expect(Schema::hasColumn('scheduled_database_backups', 'pgbackrest_backup_type'))->toBeTrue();
    expect(Schema::hasColumn('scheduled_database_backups', 'pgbackrest_require_wal_archive'))->toBeTrue();
});

test('s3_uploaded column is nullable', function () {
    $columns = Schema::getColumns('scheduled_database_backup_executions');
    $s3UploadedColumn = collect($columns)->firstWhere('name', 's3_uploaded');

    expect($s3UploadedColumn)->not->toBeNull();
    expect($s3UploadedColumn['nullable'])->toBeTrue();
});

test('scheduled database backup execution model casts s3_uploaded correctly', function () {
    $model = new ScheduledDatabaseBackupExecution;
    $casts = $model->getCasts();

    expect($casts)->toHaveKey('s3_uploaded');
    expect($casts['s3_uploaded'])->toBe('boolean');
});

test('scheduled database backup execution model casts storage deletion fields correctly', function () {
    $model = new ScheduledDatabaseBackupExecution;
    $casts = $model->getCasts();

    expect($casts)->toHaveKey('local_storage_deleted');
    expect($casts['local_storage_deleted'])->toBe('boolean');
    expect($casts)->toHaveKey('s3_storage_deleted');
    expect($casts['s3_storage_deleted'])->toBe('boolean');
});

test('scheduled database backup model casts boolean fields correctly', function () {
    $model = new ScheduledDatabaseBackup;
    $casts = $model->getCasts();

    expect($casts)->toHaveKey('save_s3');
    expect($casts['save_s3'])->toBe('boolean');
    expect($casts)->toHaveKey('disable_local_backup');
    expect($casts['disable_local_backup'])->toBe('boolean');
    expect($casts)->toHaveKey('pgbackrest_require_wal_archive');
    expect($casts['pgbackrest_require_wal_archive'])->toBe('boolean');
});

test('pgbackrest base options use mounted pgdata path and s3 repository', function () {
    $backup = ScheduledDatabaseBackup::make([
        'uuid' => 'test-backup-uuid',
        'frequency' => '0 0 * * *',
        'backup_method' => 'pgbackrest',
        'pgbackrest_backup_type' => 'incr',
        'save_s3' => true,
    ]);

    $job = new DatabaseBackupJob($backup);
    $job->backup_dir = '/data/coolify/backups/databases/test-team/test-db';
    $job->postgres_pgdata_path = '/var/lib/postgresql/data/pgdata';
    $job->database = new StandalonePostgresql([
        'postgres_user' => 'postgres',
        'postgres_db' => 'app',
    ]);
    $job->s3 = new S3Storage([
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
    ]);

    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('pgBackRestBaseOptions');
    $options = $method->invoke($job, 'coolify-test');

    expect($options)->toContain("--pg1-path='/var/lib/postgresql/data/pgdata'");
    expect($options)->not->toContain("--pg1-host='postgres-container'");
    expect($options)->toContain('--repo1-type=s3');
    expect($options)->toContain("--repo1-path='/data/coolify/backups/databases/test-team/test-db/pgbackrest/coolify-test'");
    expect($options)->toContain("--repo1-s3-bucket='test-bucket'");
    expect($options)->not->toContain("--repo1-s3-key='test-key'");
    expect($options)->not->toContain("--repo1-s3-key-secret='test-secret'");
});

test('pgbackrest requires wal archive verification by default and allows explicit opt out', function () {
    $reflection = new ReflectionClass(DatabaseBackupJob::class);
    $method = $reflection->getMethod('pgBackRestRequiresWalArchive');

    $defaultJob = new DatabaseBackupJob(ScheduledDatabaseBackup::make([
        'backup_method' => 'pgbackrest',
    ]));
    expect($method->invoke($defaultJob))->toBeTrue();

    $optOutJob = new DatabaseBackupJob(ScheduledDatabaseBackup::make([
        'backup_method' => 'pgbackrest',
        'pgbackrest_require_wal_archive' => false,
    ]));
    expect($method->invoke($optOutJob))->toBeFalse();
});

test('pgbackrest docker command uses env file without exposing secret values', function () {
    $backup = ScheduledDatabaseBackup::make([
        'uuid' => 'test-backup-uuid',
        'frequency' => '0 0 * * *',
        'backup_method' => 'pgbackrest',
        'pgbackrest_backup_type' => 'incr',
        'save_s3' => true,
    ]);

    $job = new DatabaseBackupJob($backup);
    $job->backup_dir = '/data/coolify/backups/databases/test-team/test-db';
    $job->backup_log_uuid = 'test-log-uuid';
    $job->postgres_data_path = '/var/lib/docker/volumes/test/_data';
    $job->postgres_pgdata_path = '/var/lib/postgresql/data';
    $job->container_name = 'postgres-container';
    $job->postgres_password = 'postgres-secret';
    $job->postgres_uid = '999';
    $job->postgres_gid = '999';
    $job->database = new StandalonePostgresql([
        'postgres_user' => 'postgres',
        'postgres_db' => 'app',
    ]);
    $job->database->setRelation('destination', new StandaloneDocker([
        'network' => 'coolify-test-network',
    ]));
    $job->s3 = new S3Storage([
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
    ]);

    $reflection = new ReflectionClass($job);
    $envPathMethod = $reflection->getMethod('pgBackRestRemoteEnvFilePath');
    $envPath = $envPathMethod->invoke($job);

    expect($envPath)->toBe('/tmp/coolify-pgbackrest-test-log-uuid/pgbackrest.env');

    $commandMethod = $reflection->getMethod('pgBackRestDockerCommand');
    $command = $commandMethod->invoke($job, 'coolify-test', 'backup', ['--archive-check=n'], $envPath);

    expect($command)->toContain("--env-file '/tmp/coolify-pgbackrest-test-log-uuid/pgbackrest.env'");
    expect($command)->toContain('--archive-check=n');
    expect($command)->not->toContain('test-key');
    expect($command)->not->toContain('test-secret');
    expect($command)->not->toContain('postgres-secret');
    expect($command)->not->toContain('--repo1-s3-key');
    expect($command)->not->toContain('--repo1-s3-key-secret');

    $psqlCommandMethod = $reflection->getMethod('postgresPsqlCommand');
    $psqlCommand = $psqlCommandMethod->invoke($job, 'SHOW archive_mode;');

    expect($psqlCommand)->toContain('POSTGRES_PASSWORD');
    expect($psqlCommand)->not->toContain('postgres-secret');

    $envMethod = $reflection->getMethod('pgBackRestEnvFileContents');
    $env = $envMethod->invoke($job);

    expect($env)->toContain('PGHOST=postgres-container');
    expect($env)->toContain('PGPORT=5432');
    expect($env)->toContain('PGPASSWORD=postgres-secret');
    expect($env)->toContain('BACKREST_UID=999');
    expect($env)->toContain('BACKREST_GID=999');
    expect($env)->toContain('PGBACKREST_REPO1_S3_KEY=test-key');
    expect($env)->toContain('PGBACKREST_REPO1_S3_KEY_SECRET=test-secret');
});

test('pgbackrest env file rejects injected variables and invalid names', function () {
    $job = new DatabaseBackupJob(ScheduledDatabaseBackup::make([
        'backup_method' => 'pgbackrest',
        'save_s3' => true,
    ]));
    $job->container_name = 'postgres-container';
    $job->postgres_password = "safe-password\nPGBACKREST_REPO1_S3_KEY_SECRET=injected";
    $job->s3 = new S3Storage([
        'key' => 'test-key',
        'secret' => 'test-secret',
    ]);

    $reflection = new ReflectionClass($job);
    $contentsMethod = $reflection->getMethod('pgBackRestEnvFileContents');
    $lineMethod = $reflection->getMethod('pgBackRestEnvFileLine');

    expect(fn () => $contentsMethod->invoke($job))
        ->toThrow(Exception::class, 'contains unsupported control characters');

    expect(fn () => $lineMethod->invoke($job, 'BAD-NAME', 'value'))
        ->toThrow(Exception::class, 'Invalid pgBackRest environment variable name.');
});

test('deleteBackupsS3 deletes only explicit objects, not directory prefixes', function () {
    $s3 = S3Storage::unguarded(fn () => new S3Storage([
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
    ]));

    $disk = new class
    {
        public array $deleted = [];

        public int $deleteDirectoryCalls = 0;

        public function delete($paths): bool
        {
            $this->deleted = (array) $paths;

            return true;
        }

        public function deleteDirectory($directory): bool
        {
            $this->deleteDirectoryCalls++;

            return true;
        }
    };

    Storage::shouldReceive('build')->once()->andReturn($disk);

    deleteBackupsS3('data/coolify/backups/team/db/pgbackrest/coolify-backup/', $s3);

    expect($disk->deleted)->toBe(['data/coolify/backups/team/db/pgbackrest/coolify-backup/']);
    expect($disk->deleteDirectoryCalls)->toBe(0);
});

test('deleteBackupsS3Prefix only accepts scoped pgbackrest repository prefixes', function () {
    $s3 = S3Storage::unguarded(fn () => new S3Storage([
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
    ]));

    foreach ([
        'data/coolify/backups/team/db/',
        'data/coolify/backups/team/db/pgbackrest/not-coolify/',
        'tmp/backups/team/db/pgbackrest/coolify-backup/',
        'data/coolify/backups/team/../db/pgbackrest/coolify-backup/',
        'data/coolify/backups/team/db/pgbackrest/coolify-backup',
    ] as $prefix) {
        expect(fn () => deleteBackupsS3Prefix($prefix, $s3))
            ->toThrow(InvalidArgumentException::class, 'Invalid pgBackRest S3 repository prefix.');
    }

    $disk = new class
    {
        public ?string $deletedDirectory = null;

        public function deleteDirectory($directory): bool
        {
            $this->deletedDirectory = $directory;

            return true;
        }
    };

    Storage::shouldReceive('build')->once()->andReturn($disk);

    deleteBackupsS3Prefix('data/coolify/backups/team/db/pgbackrest/coolify-backup/', $s3);

    expect($disk->deletedDirectory)->toBe('data/coolify/backups/team/db/pgbackrest/coolify-backup');
});

test('pgbackrest repository key prefix can be derived without execution filenames', function () {
    $team = Team::factory()->create([
        'name' => 'Test Team',
    ]);

    $database = new StandalonePostgresql([
        'uuid' => 'postgres-uuid',
        'name' => 'Main PostgreSQL',
    ]);

    $backup = ScheduledDatabaseBackup::make([
        'uuid' => 'backup-uuid',
        'backup_method' => 'pgbackrest',
        'team_id' => $team->id,
    ]);
    $backup->setRelation('team', $team);
    $backup->setRelation('database', $database);

    expect(pgBackRestRepositoryKeyPrefix($backup))->toBe('data/coolify/backups/databases/test-team-'.$team->id.'/main-postgresql-postgres-uuid/pgbackrest/coolify-backup-uuid/');
});

test('upload_to_s3 throws exception and disables s3 when storage is null', function () {
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        's3_storage_id' => 99999,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => Team::factory()->create()->id,
    ]);

    $job = new DatabaseBackupJob($backup);

    $reflection = new ReflectionClass($job);
    $s3Property = $reflection->getProperty('s3');
    $s3Property->setValue($job, null);

    $method = $reflection->getMethod('upload_to_s3');

    expect(fn () => $method->invoke($job))
        ->toThrow(Exception::class, 'S3 storage configuration is missing or has been deleted');

    $backup->refresh();
    expect($backup->save_s3)->toBeFalsy();
    expect($backup->s3_storage_id)->toBeNull();
});

test('deleting s3 storage disables s3 on linked backups', function () {
    $team = Team::factory()->create();

    $s3 = S3Storage::unguarded(fn () => S3Storage::create([
        'name' => 'Test S3',
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
    ]));

    $backup1 = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        's3_storage_id' => $s3->id,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
    ]);

    $backup2 = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        's3_storage_id' => $s3->id,
        'database_type' => 'App\Models\StandaloneMysql',
        'database_id' => 2,
        'team_id' => $team->id,
    ]);

    // Unrelated backup should not be affected
    $unrelatedBackup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        's3_storage_id' => null,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 3,
        'team_id' => $team->id,
    ]);

    $s3->delete();

    $backup1->refresh();
    $backup2->refresh();
    $unrelatedBackup->refresh();

    expect($backup1->save_s3)->toBeFalsy();
    expect($backup1->s3_storage_id)->toBeNull();
    expect($backup2->save_s3)->toBeFalsy();
    expect($backup2->s3_storage_id)->toBeNull();
    expect($unrelatedBackup->save_s3)->toBeTruthy();
});

test('failed method does not overwrite successful backup status', function () {
    $team = Team::factory()->create();

    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
    ]);

    $log = ScheduledDatabaseBackupExecution::create([
        'uuid' => 'test-uuid-success-guard',
        'database_name' => 'test_db',
        'filename' => '/backup/test.dmp',
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'success',
        'message' => 'Backup completed successfully',
        'size' => 1024,
    ]);

    $job = new DatabaseBackupJob($backup);

    $reflection = new ReflectionClass($job);

    $teamProp = $reflection->getProperty('team');
    $teamProp->setValue($job, $team);

    $logUuidProp = $reflection->getProperty('backup_log_uuid');
    $logUuidProp->setValue($job, 'test-uuid-success-guard');

    // Simulate a post-backup failure (e.g. notification error)
    $job->failed(new Exception('Request to the Resend API failed'));

    $log->refresh();
    expect($log->status)->toBe('success');
    expect($log->message)->toBe('Backup completed successfully');
    expect((int) $log->size)->toBe(1024);
});

test('failed method updates status when backup was not successful', function () {
    $team = Team::factory()->create();

    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
    ]);

    $log = ScheduledDatabaseBackupExecution::create([
        'uuid' => 'test-uuid-pending-guard',
        'database_name' => 'test_db',
        'filename' => '/backup/test.dmp',
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'pending',
    ]);

    $job = new DatabaseBackupJob($backup);

    $reflection = new ReflectionClass($job);

    $teamProp = $reflection->getProperty('team');
    $teamProp->setValue($job, $team);

    $logUuidProp = $reflection->getProperty('backup_log_uuid');
    $logUuidProp->setValue($job, 'test-uuid-pending-guard');

    $job->failed(new Exception('Some real failure'));

    $log->refresh();
    expect($log->status)->toBe('failed');
    expect($log->message)->toContain('Some real failure');
});

test('s3 storage has scheduled backups relationship', function () {
    $team = Team::factory()->create();

    $s3 = S3Storage::unguarded(fn () => S3Storage::create([
        'name' => 'Test S3',
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
    ]));

    ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => true,
        's3_storage_id' => $s3->id,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
    ]);

    expect($s3->scheduledBackups()->count())->toBe(1);
});
