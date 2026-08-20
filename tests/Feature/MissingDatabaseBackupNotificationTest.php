<?php

use App\Jobs\CheckMissingDatabaseBackupsJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Team;
use App\Notifications\Database\BackupMissing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function missingBackupSchedule(Team $team, array $attributes = []): ScheduledDatabaseBackup
{
    $backup = ScheduledDatabaseBackup::create(array_merge([
        'enabled' => true,
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => 'App\\Models\\StandalonePostgresql',
        'database_id' => 999,
        'team_id' => $team->id,
        'missing_backup_notification_days' => 2,
    ], $attributes));

    ScheduledDatabaseBackup::whereKey($backup->id)->update(['created_at' => now()->subDays(3)]);

    return $backup->refresh();
}

function teamWithBackupFailureNotifications(): Team
{
    $team = Team::create(['name' => 'Test team']);
    $team->emailNotificationSettings->update([
        'smtp_enabled' => true,
        'backup_failure_email_notifications' => true,
    ]);

    return $team;
}

it('notifies the team when an enabled backup has no executions for the configured days', function () {
    Notification::fake();
    $team = teamWithBackupFailureNotifications();
    $backup = missingBackupSchedule($team);

    (new CheckMissingDatabaseBackupsJob)->handle();

    Notification::assertSentTo($team, BackupMissing::class, fn (BackupMissing $notification) => $notification->backup->is($backup));
    expect($backup->fresh()->missing_backup_notification_sent_at)->not->toBeNull();
});

it('does not notify for recent disabled or unconfigured backup schedules', function () {
    Notification::fake();
    $team = teamWithBackupFailureNotifications();
    missingBackupSchedule($team, ['enabled' => false]);
    missingBackupSchedule($team, ['missing_backup_notification_days' => 0]);
    $recent = missingBackupSchedule($team);
    ScheduledDatabaseBackup::whereKey($recent->id)->update(['created_at' => now()->subDay()]);

    (new CheckMissingDatabaseBackupsJob)->handle();

    Notification::assertNothingSent();
});

it('notifies once per period without executions and rearms after another execution', function () {
    Notification::fake();
    $team = teamWithBackupFailureNotifications();
    $backup = missingBackupSchedule($team);

    (new CheckMissingDatabaseBackupsJob)->handle();
    (new CheckMissingDatabaseBackupsJob)->handle();

    Notification::assertSentToTimes($team, BackupMissing::class, 1);

    Carbon::setTestNow(now()->addMinute());
    $execution = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'success',
        'database_name' => 'app',
    ]);
    Carbon::setTestNow(now()->addDays(3));

    (new CheckMissingDatabaseBackupsJob)->handle();

    Notification::assertSentToTimes($team, BackupMissing::class, 2);
});

it('preserves the last execution checkpoint when execution history is deleted', function () {
    Notification::fake();
    $team = teamWithBackupFailureNotifications();
    $backup = missingBackupSchedule($team);

    (new CheckMissingDatabaseBackupsJob)->handle();
    Carbon::setTestNow(now()->addMinute());
    $execution = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'success',
        'database_name' => 'app',
    ]);
    $execution->delete();
    Carbon::setTestNow(now()->addDays(3));

    (new CheckMissingDatabaseBackupsJob)->handle();

    Notification::assertSentToTimes($team, BackupMissing::class, 2);
});

it('waits to mark an incident sent until a notification channel is enabled', function () {
    Notification::fake();
    $team = Team::create(['name' => 'Test team']);
    $backup = missingBackupSchedule($team);

    (new CheckMissingDatabaseBackupsJob)->handle();
    expect($backup->fresh()->missing_backup_notification_sent_at)->toBeNull();

    $team->emailNotificationSettings->update([
        'smtp_enabled' => true,
        'backup_failure_email_notifications' => true,
    ]);
    (new CheckMissingDatabaseBackupsJob)->handle();

    Notification::assertSentTo($team, BackupMissing::class);
});
