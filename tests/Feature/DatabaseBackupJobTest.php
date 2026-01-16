<?php

use App\Models\ScheduledDatabaseBackupExecution;
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
