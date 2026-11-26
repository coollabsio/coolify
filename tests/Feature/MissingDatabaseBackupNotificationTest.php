<?php

use App\Jobs\CheckMissingDatabaseBackupsJob;
use App\Models\NotificationThrottle;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Team;
use App\Notifications\Database\BackupMissing;
use Illuminate\Contracts\Notifications\Dispatcher;
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
    expect(NotificationThrottle::wasSent($backup, BackupMissing::class))->toBeTrue();
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
    expect(NotificationThrottle::wasSent($backup, BackupMissing::class))->toBeFalse();

    $team->emailNotificationSettings->update([
        'smtp_enabled' => true,
        'backup_failure_email_notifications' => true,
    ]);
    (new CheckMissingDatabaseBackupsJob)->handle();

    Notification::assertSentTo($team, BackupMissing::class);
});

it('releases the missing backup claim when sending the notification throws', function () {
    $team = teamWithBackupFailureNotifications();
    $backup = missingBackupSchedule($team);
    $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP is down')));

    expect(fn () => (new CheckMissingDatabaseBackupsJob)->handle())->toThrow(RuntimeException::class, 'SMTP is down')
        ->and(NotificationThrottle::wasSent($backup, BackupMissing::class))->toBeFalse();
});
