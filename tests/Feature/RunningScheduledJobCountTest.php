<?php

use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\ScheduledVolumeBackup;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $team = Team::factory()->create();

    $this->task = ScheduledTask::factory()->create(['team_id' => $team->id, 'timeout' => 300]);
    $this->databaseBackup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
        'timeout' => 3600,
    ]);
    $this->volumeBackup = ScheduledVolumeBackup::create([
        'uuid' => fake()->uuid(),
        'backupable_type' => 'App\Models\LocalPersistentVolume',
        'backupable_id' => 1,
        'team_id' => $team->id,
        'frequency' => 'daily',
        'enabled' => true,
        'save_s3' => false,
        'timeout' => 7200,
    ]);
});

it('counts running scheduled tasks, database backups, and volume backups', function () {
    ScheduledTaskExecution::create(['scheduled_task_id' => $this->task->id, 'status' => 'running']);
    ScheduledDatabaseBackupExecution::create(['scheduled_database_backup_id' => $this->databaseBackup->id, 'database_name' => 'db', 'status' => 'running']);
    $this->volumeBackup->executions()->create();

    expect(runningScheduledJobCount())->toBe(3);
});

it('does not count finished runs', function () {
    ScheduledTaskExecution::create(['scheduled_task_id' => $this->task->id, 'status' => 'success']);
    ScheduledDatabaseBackupExecution::create(['scheduled_database_backup_id' => $this->databaseBackup->id, 'database_name' => 'db', 'status' => 'failed']);
    $this->volumeBackup->executions()->create(['status' => 'success']);

    expect(runningScheduledJobCount())->toBe(0);
});

it('does not count running rows older than their job timeout', function () {
    ScheduledTaskExecution::create(['scheduled_task_id' => $this->task->id, 'status' => 'running']);
    ScheduledDatabaseBackupExecution::create(['scheduled_database_backup_id' => $this->databaseBackup->id, 'database_name' => 'db', 'status' => 'running']);
    $this->volumeBackup->executions()->create();

    $this->travel(30)->minutes();
    expect(runningScheduledJobCount())->toBe(2);

    $this->travel(40)->minutes();
    expect(runningScheduledJobCount())->toBe(1);

    $this->travel(60)->minutes();
    expect(runningScheduledJobCount())->toBe(0);
});
