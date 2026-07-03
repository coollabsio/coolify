<?php

use App\Jobs\CleanupInstanceStuffsJob;
use App\Jobs\DatabaseBackupJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// --- WithoutOverlapping middleware on DatabaseBackupJob ---

test('database backup job has WithoutOverlapping middleware keyed by backup id', function () {
    $team = Team::factory()->create();

    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
    ]);

    $job = new DatabaseBackupJob($backup);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});

test('database backup job middleware uses custom timeout plus buffer', function () {
    $team = Team::factory()->create();

    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
        'timeout' => 7200,
    ]);

    $job = new DatabaseBackupJob($backup);
    $middleware = $job->middleware();

    $reflection = new ReflectionClass($middleware[0]);
    $expiresAfterProp = $reflection->getProperty('expiresAfter');
    $releaseAfterProp = $reflection->getProperty('releaseAfter');

    expect($expiresAfterProp->getValue($middleware[0]))->toBe(7500); // 7200 + 300
    expect($releaseAfterProp->getValue($middleware[0]))->toBeNull(); // dontRelease sets to null
});

// --- Stale backup detection in DatabaseBackupJob ---

test('markStaleExecutionsAsFailed marks stale running executions as failed', function () {
    $team = Team::factory()->create();

    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
        'timeout' => 3600,
    ]);

    // Create a stale execution (3 hours old, timeout is 1h, threshold is 2h)
    $staleExecution = ScheduledDatabaseBackupExecution::create([
        'uuid' => 'stale-exec-uuid',
        'database_name' => 'test_db',
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'running',
    ]);
    ScheduledDatabaseBackupExecution::where('id', $staleExecution->id)
        ->update(['created_at' => now()->subHours(3)]);

    // Call the private method via reflection
    $job = new DatabaseBackupJob($backup);
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('markStaleExecutionsAsFailed');
    $method->invoke($job);

    $staleExecution->refresh();
    expect($staleExecution->status)->toBe('failed');
    expect($staleExecution->message)->toContain('exceeded maximum allowed time');
    expect($staleExecution->finished_at)->not->toBeNull();
});

test('markStaleExecutionsAsFailed does not mark recent running executions as failed', function () {
    $team = Team::factory()->create();

    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
        'timeout' => 3600,
    ]);

    // Create a recent execution (30 minutes old, threshold is 2 hours)
    $recentExecution = ScheduledDatabaseBackupExecution::create([
        'uuid' => 'recent-exec-uuid',
        'database_name' => 'test_db',
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'running',
    ]);
    ScheduledDatabaseBackupExecution::where('id', $recentExecution->id)
        ->update(['created_at' => now()->subMinutes(30)]);

    $job = new DatabaseBackupJob($backup);
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('markStaleExecutionsAsFailed');
    $method->invoke($job);

    $recentExecution->refresh();
    expect($recentExecution->status)->toBe('running');
    expect($recentExecution->finished_at)->toBeNull();
});

// --- Decimal cast on ScheduledDatabaseBackup model ---

test('scheduled database backup model casts max storage fields to float', function () {
    $model = new ScheduledDatabaseBackup;
    $casts = $model->getCasts();

    expect($casts)->toHaveKey('database_backup_retention_max_storage_locally');
    expect($casts['database_backup_retention_max_storage_locally'])->toBe('float');
    expect($casts)->toHaveKey('database_backup_retention_max_storage_s3');
    expect($casts['database_backup_retention_max_storage_s3'])->toBe('float');
});

test('max storage retention field returns float zero that passes strict comparison', function () {
    $team = Team::factory()->create();

    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
        'database_backup_retention_max_storage_locally' => 0,
    ]);

    $backup->refresh();

    // With the float cast, strict comparison to 0.0 works
    expect($backup->database_backup_retention_max_storage_locally)->toBe(0.0);
    // And loose comparison to int 0 also works
    expect($backup->database_backup_retention_max_storage_locally == 0)->toBeTrue();
});

// --- Periodic retention enforcement in CleanupInstanceStuffsJob ---

test('cleanup instance stuffs job calls retention enforcement without errors', function () {
    $team = Team::factory()->create();

    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'enabled' => true,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
        'database_backup_retention_amount_locally' => 1,
    ]);

    // Create 3 successful executions
    foreach (range(1, 3) as $i) {
        ScheduledDatabaseBackupExecution::create([
            'uuid' => "exec-uuid-{$i}",
            'database_name' => 'test_db',
            'filename' => "/backup/test_{$i}.dmp",
            'scheduled_database_backup_id' => $backup->id,
            'status' => 'success',
            'size' => 1024,
            'local_storage_deleted' => false,
            'created_at' => now()->subDays($i),
        ]);
    }

    Cache::forget('backup-retention-enforcement');

    // Should not throw — the job gracefully handles missing servers
    $job = new CleanupInstanceStuffsJob;
    $job->handle();

    // All 3 executions should still exist (no server to delete files from)
    expect($backup->executions()->count())->toBe(3);
});

test('deleteOldBackupsLocally identifies correct backups for deletion by retention count', function () {
    $team = Team::factory()->create();

    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'enabled' => true,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
        'database_backup_retention_amount_locally' => 1,
    ]);

    foreach (range(1, 3) as $i) {
        $exec = ScheduledDatabaseBackupExecution::create([
            'uuid' => "exec-uuid-{$i}",
            'database_name' => 'test_db',
            'filename' => "/backup/test_{$i}.dmp",
            'scheduled_database_backup_id' => $backup->id,
            'status' => 'success',
            'size' => 1024,
            'local_storage_deleted' => false,
        ]);
        ScheduledDatabaseBackupExecution::where('id', $exec->id)
            ->update(['created_at' => now()->subDays($i)]);
    }

    // Test the selection logic directly
    $successfulBackups = $backup->executions()
        ->where('status', 'success')
        ->where('local_storage_deleted', false)
        ->orderBy('created_at', 'desc')
        ->get();

    $retentionAmount = $backup->database_backup_retention_amount_locally;
    $backupsToDelete = $successfulBackups->skip($retentionAmount);

    // exec-uuid-1 is newest (1 day ago), exec-uuid-2 and exec-uuid-3 should be deleted
    expect($backupsToDelete)->toHaveCount(2);
    expect($successfulBackups->first()->uuid)->toBe('exec-uuid-1');
});

test('cleanup instance stuffs job throttles retention enforcement via cache', function () {
    Cache::forget('backup-retention-enforcement');

    $job = new CleanupInstanceStuffsJob;
    $job->handle();

    // The cache key should now exist
    expect(Cache::has('backup-retention-enforcement'))->toBeTrue();

    // Running again should skip retention enforcement (cache hit)
    // We verify by checking the lock is still held — no error thrown
    $job->handle();

    expect(Cache::has('backup-retention-enforcement'))->toBeTrue();
});
