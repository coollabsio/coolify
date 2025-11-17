<?php

use App\Models\ScheduledDatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('scheduled_database_backups table has pgbackrest columns', function () {
    expect(Schema::hasColumn('scheduled_database_backups', 'backup_type'))->toBeTrue();
    expect(Schema::hasColumn('scheduled_database_backups', 'pgbackrest_type'))->toBeTrue();
    expect(Schema::hasColumn('scheduled_database_backups', 'pgbackrest_stanza'))->toBeTrue();
    expect(Schema::hasColumn('scheduled_database_backups', 'pgbackrest_retention_full'))->toBeTrue();
    expect(Schema::hasColumn('scheduled_database_backups', 'pgbackrest_retention_diff'))->toBeTrue();
    expect(Schema::hasColumn('scheduled_database_backups', 'pgbackrest_block_incremental'))->toBeTrue();
    expect(Schema::hasColumn('scheduled_database_backups', 'pgbackrest_process_max'))->toBeTrue();
});

test('backup_type column has correct default', function () {
    $columns = Schema::getColumns('scheduled_database_backups');
    $backupTypeColumn = collect($columns)->firstWhere('name', 'backup_type');

    expect($backupTypeColumn)->not->toBeNull();
    expect($backupTypeColumn['default'])->toBe('pg_dump');
});

test('pgbackrest columns are nullable', function () {
    $columns = Schema::getColumns('scheduled_database_backups');
    $pgbackrestTypeColumn = collect($columns)->firstWhere('name', 'pgbackrest_type');
    $pgbackrestStanzaColumn = collect($columns)->firstWhere('name', 'pgbackrest_stanza');
    $pgbackrestRetentionDiffColumn = collect($columns)->firstWhere('name', 'pgbackrest_retention_diff');

    expect($pgbackrestTypeColumn['nullable'])->toBeTrue();
    expect($pgbackrestStanzaColumn['nullable'])->toBeTrue();
    expect($pgbackrestRetentionDiffColumn['nullable'])->toBeTrue();
});

test('scheduled database backup model casts pgbackrest fields correctly', function () {
    $model = new ScheduledDatabaseBackup;
    $casts = $model->casts();

    expect($casts)->toHaveKey('pgbackrest_block_incremental');
    expect($casts['pgbackrest_block_incremental'])->toBe('boolean');
    expect($casts)->toHaveKey('pgbackrest_retention_full');
    expect($casts['pgbackrest_retention_full'])->toBe('integer');
    expect($casts)->toHaveKey('pgbackrest_retention_diff');
    expect($casts['pgbackrest_retention_diff'])->toBe('integer');
    expect($casts)->toHaveKey('pgbackrest_process_max');
    expect($casts['pgbackrest_process_max'])->toBe('integer');
});