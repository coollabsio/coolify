<?php

use App\Models\NotificationThrottle;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Database\BackupMissing;
use App\Notifications\Server\Reachable;
use App\Notifications\Server\Unreachable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
});

it('moves the old notification markers into notification_throttles and back', function () {
    $team = Team::factory()->create();
    $localhost = Server::factory()->create(['id' => 0, 'team_id' => $team->id]);
    $notifiedServer = Server::factory()->create(['team_id' => $team->id]);
    $quietServer = Server::factory()->create(['team_id' => $team->id]);
    $notifiedBackup = ScheduledDatabaseBackup::create([
        'enabled' => true,
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => 'App\\Models\\StandalonePostgresql',
        'database_id' => 999,
        'team_id' => $team->id,
    ]);
    $quietBackup = $notifiedBackup->replicate(['uuid']);
    $quietBackup->save();

    $migration = require database_path('migrations/2026_09_29_083244_create_notification_throttles_table.php');
    $migration->down();

    DB::table('servers')->whereIn('id', [$localhost->id, $notifiedServer->id])->update(['unreachable_notification_sent' => true]);
    DB::table('scheduled_database_backups')->where('id', $notifiedBackup->id)->update(['missing_backup_notification_sent_at' => '2026-09-01 10:00:00']);

    $migration->up();

    expect(Schema::hasColumn('servers', 'unreachable_notification_sent'))->toBeFalse()
        ->and(Schema::hasColumn('scheduled_database_backups', 'missing_backup_notification_sent_at'))->toBeFalse()
        ->and(NotificationThrottle::query()->count())->toBe(3)
        ->and(NotificationThrottle::wasSent($localhost, Unreachable::class))->toBeTrue()
        ->and(NotificationThrottle::wasSent($notifiedServer, Unreachable::class))->toBeTrue()
        ->and(NotificationThrottle::wasSent($quietServer, Unreachable::class))->toBeFalse()
        ->and(NotificationThrottle::wasSent($quietBackup, BackupMissing::class))->toBeFalse()
        ->and(NotificationThrottle::query()
            ->whereMorphedTo('notifiable', $notifiedBackup)
            ->where('notification', BackupMissing::class)
            ->sole()
            ->sent_at
            ->toDateTimeString())->toBe('2026-09-01 10:00:00');

    $migration->down();

    expect(Schema::hasTable('notification_throttles'))->toBeFalse()
        ->and(DB::table('servers')->where('unreachable_notification_sent', true)->pluck('id')->sort()->values()->all())
        ->toBe([$localhost->id, $notifiedServer->id])
        ->and(DB::table('scheduled_database_backups')->where('id', $notifiedBackup->id)->value('missing_backup_notification_sent_at'))
        ->toStartWith('2026-09-01 10:00:00')
        ->and(DB::table('scheduled_database_backups')->where('id', $quietBackup->id)->value('missing_backup_notification_sent_at'))
        ->toBeNull();

    $migration->up();
});

it('keeps a migrated unreachable marker until the server is reachable again, however long ago it was set', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $team->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'server_unreachable_email_notifications' => true,
        'server_reachable_email_notifications' => true,
    ]);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $migration = require database_path('migrations/2026_09_29_083244_create_notification_throttles_table.php');
    $migration->down();
    DB::table('servers')->where('id', $server->id)->update(['unreachable_notification_sent' => true, 'updated_at' => now()]);
    $migration->up();

    $server->settings()->update(['is_reachable' => false]);
    $server->forceFill(['unreachable_count' => 5])->save();

    $this->travel(400)->days();
    $server->fresh()->isReachableChanged();
    Notification::assertNothingSent();

    $server->settings()->update(['is_reachable' => true]);
    $server->fresh()->isReachableChanged();
    Notification::assertSentTo($team, Reachable::class);
    expect(NotificationThrottle::wasSent($server, Unreachable::class))->toBeFalse();
});
