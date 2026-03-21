<?php

use App\Jobs\DatabaseBackupJob;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('scheduled_database_backup_executions table has s3_uploaded column', function () {
    expect(Schema::hasColumn('scheduled_database_backup_executions', 's3_uploaded'))->toBeTrue();
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

    $s3 = S3Storage::create([
        'name' => 'Test S3',
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
    ]);

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

test('s3 storage has scheduled backups relationship', function () {
    $team = Team::factory()->create();

    $s3 = S3Storage::create([
        'name' => 'Test S3',
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
    ]);

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
