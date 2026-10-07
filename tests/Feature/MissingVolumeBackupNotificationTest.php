<?php

use App\Jobs\CheckMissingVolumeBackupsJob;
use App\Models\LocalPersistentVolume;
use App\Models\NotificationThrottle;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Team;
use App\Notifications\VolumeBackup\BackupMissing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function missingVolumeBackupSchedule(Team $team, array $attributes = []): ScheduledVolumeBackup
{
    $backup = ScheduledVolumeBackup::create(array_merge([
        'backupable_type' => (new LocalPersistentVolume)->getMorphClass(),
        'backupable_id' => ScheduledVolumeBackup::query()->max('backupable_id') + 1000,
        'team_id' => $team->id,
        'frequency' => '0 0 * * *',
        'enabled' => true,
        'missing_backup_notification_days' => 2,
    ], $attributes));

    ScheduledVolumeBackup::whereKey($backup->id)->update(['created_at' => now()->subDays(3)]);

    return $backup->refresh();
}

function teamWithVolumeBackupFailureNotifications(): Team
{
    $team = Team::create(['name' => 'Test team']);
    $team->emailNotificationSettings->update([
        'smtp_enabled' => true,
        'backup_failure_email_notifications' => true,
    ]);

    return $team;
}

it('notifies the team when an enabled volume backup has no executions for the configured days', function () {
    Notification::fake();
    $team = teamWithVolumeBackupFailureNotifications();
    $backup = missingVolumeBackupSchedule($team);

    (new CheckMissingVolumeBackupsJob)->handle();

    Notification::assertSentTo($team, BackupMissing::class, fn (BackupMissing $notification) => $notification->backup->is($backup)
        && $notification->lastExecutionAt === null
        && $notification->toWebhook()['event'] === 'volume_backup_missing');
    expect(NotificationThrottle::wasSent($backup, BackupMissing::class))->toBeTrue()
        ->and((string) (new BackupMissing($backup, null))->toMail()->render())->toContain('This schedule has never produced an execution.');
});

it('does not notify for recent, disabled or unconfigured volume backup schedules', function () {
    Notification::fake();
    $team = teamWithVolumeBackupFailureNotifications();
    missingVolumeBackupSchedule($team, ['enabled' => false]);
    missingVolumeBackupSchedule($team, ['missing_backup_notification_days' => 0]);
    $recentlyCreated = missingVolumeBackupSchedule($team);
    ScheduledVolumeBackup::whereKey($recentlyCreated->id)->update(['created_at' => now()->subDay()]);
    $recentlyExecuted = missingVolumeBackupSchedule($team);
    ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $recentlyExecuted->id,
        'status' => 'success',
    ]);

    (new CheckMissingVolumeBackupsJob)->handle();

    Notification::assertNothingSent();
});

it('does not notify when the team has no backup failure channels', function () {
    Notification::fake();
    $team = Team::create(['name' => 'Test team']);
    $backup = missingVolumeBackupSchedule($team);

    (new CheckMissingVolumeBackupsJob)->handle();

    Notification::assertNothingSent();
    expect(NotificationThrottle::wasSent($backup, BackupMissing::class))->toBeFalse();
});

it('notifies once per period without volume backup executions and rearms after another execution', function () {
    Notification::fake();
    $team = teamWithVolumeBackupFailureNotifications();
    $backup = missingVolumeBackupSchedule($team);

    (new CheckMissingVolumeBackupsJob)->handle();
    (new CheckMissingVolumeBackupsJob)->handle();

    Notification::assertSentToTimes($team, BackupMissing::class, 1);

    Carbon::setTestNow(now()->addMinute());
    $execution = ScheduledVolumeBackupExecution::create([
        'scheduled_volume_backup_id' => $backup->id,
        'status' => 'success',
    ]);
    Carbon::setTestNow(now()->addDays(3));

    (new CheckMissingVolumeBackupsJob)->handle();

    Notification::assertSentToTimes($team, BackupMissing::class, 2);
    Notification::assertSentTo($team, BackupMissing::class, fn (BackupMissing $notification) => $notification->lastExecutionAt?->equalTo($execution->created_at) === true);
});
