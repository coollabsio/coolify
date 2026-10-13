<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SERVER = 'App\Models\Server';

    private const BACKUP = 'App\Models\ScheduledDatabaseBackup';

    private const UNREACHABLE = 'App\Notifications\Server\Unreachable';

    private const BACKUP_MISSING = 'App\Notifications\Database\BackupMissing';

    /**
     * Moves the per-resource "notification sent" markers into one table.
     */
    public function up(): void
    {
        Schema::create('notification_throttles', function (Blueprint $table) {
            $table->id();
            $table->morphs('notifiable');
            $table->string('notification');
            $table->timestampTz('sent_at');
            $table->unique(['notifiable_type', 'notifiable_id', 'notification']);
        });

        DB::table('servers')
            ->where('unreachable_notification_sent', true)
            ->orderBy('id')
            ->select(['id', 'updated_at'])
            ->chunk(500, function ($servers) {
                DB::table('notification_throttles')->insertOrIgnore($servers->map(fn ($server) => [
                    'notifiable_type' => self::SERVER,
                    'notifiable_id' => $server->id,
                    'notification' => self::UNREACHABLE,
                    'sent_at' => $server->updated_at ?? now(),
                ])->all());
            });

        DB::table('scheduled_database_backups')
            ->whereNotNull('missing_backup_notification_sent_at')
            ->orderBy('id')
            ->select(['id', 'missing_backup_notification_sent_at'])
            ->chunk(500, function ($backups) {
                DB::table('notification_throttles')->insertOrIgnore($backups->map(fn ($backup) => [
                    'notifiable_type' => self::BACKUP,
                    'notifiable_id' => $backup->id,
                    'notification' => self::BACKUP_MISSING,
                    'sent_at' => $backup->missing_backup_notification_sent_at,
                ])->all());
            });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('unreachable_notification_sent');
        });
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn('missing_backup_notification_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('unreachable_notification_sent')->default(false);
        });
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->timestamp('missing_backup_notification_sent_at')->nullable();
        });

        DB::table('notification_throttles')
            ->where('notifiable_type', self::SERVER)
            ->where('notification', self::UNREACHABLE)
            ->orderBy('id')
            ->chunk(500, function ($throttles) {
                DB::table('servers')
                    ->whereIn('id', $throttles->pluck('notifiable_id'))
                    ->update(['unreachable_notification_sent' => true]);
            });

        DB::table('notification_throttles')
            ->where('notifiable_type', self::BACKUP)
            ->where('notification', self::BACKUP_MISSING)
            ->orderBy('id')
            ->chunk(500, function ($throttles) {
                foreach ($throttles as $throttle) {
                    DB::table('scheduled_database_backups')
                        ->where('id', $throttle->notifiable_id)
                        ->update(['missing_backup_notification_sent_at' => $throttle->sent_at]);
                }
            });

        Schema::dropIfExists('notification_throttles');
    }
};
