<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $teams = DB::table('teams')->get();

        foreach ($teams as $team) {
            try {
                DB::table('discord_notification_settings')->updateOrInsert(
                    ['team_id' => $team->id],
                    [
                        'discord_enabled' => $team->discord_enabled ?? false,
                        'discord_webhook_url' => $team->discord_webhook_url ? Crypt::encryptString($team->discord_webhook_url) : null,

                        'deployment_success_discord_notifications' => $team->discord_notifications_deployments ?? false,
                        'deployment_failure_discord_notifications' => $team->discord_notifications_deployments ?? true,
                        'backup_success_discord_notifications' => $team->discord_notifications_database_backups ?? false,
                        'backup_failure_discord_notifications' => $team->discord_notifications_database_backups ?? true,
                        'scheduled_task_success_discord_notifications' => $team->discord_notifications_scheduled_tasks ?? false,
                        'scheduled_task_failure_discord_notifications' => $team->discord_notifications_scheduled_tasks ?? true,
                        'status_change_discord_notifications' => $team->discord_notifications_status_changes ?? false,
                        'server_disk_usage_discord_notifications' => $team->discord_notifications_server_disk_usage ?? true,
                    ]
                );
            } catch (Exception $e) {
                \Log::error('Error migrating discord notification settings from teams table: '.$e->getMessage());
            }
        }

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'discord_enabled',
                'discord_webhook_url',
                'discord_notifications_test',
                'discord_notifications_deployments',
                'discord_notifications_status_changes',
                'discord_notifications_database_backups',
                'discord_notifications_scheduled_tasks',
                'discord_notifications_server_disk_usage',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('discord_enabled')->default(false);
            $table->string('discord_webhook_url')->nullable();

            $table->boolean('discord_notifications_test')->default(true);
            $table->boolean('discord_notifications_deployments')->default(true);
            $table->boolean('discord_notifications_status_changes')->default(true);
            $table->boolean('discord_notifications_database_backups')->default(true);
            $table->boolean('discord_notifications_scheduled_tasks')->default(true);
            $table->boolean('discord_notifications_server_disk_usage')->default(true);
        });

        $settings = DB::table('discord_notification_settings')->get();
        foreach ($settings as $setting) {
            try {
                DB::table('teams')
                    ->where('id', $setting->team_id)
                    ->update([
                        'discord_enabled' => $setting->discord_enabled,
                        'discord_webhook_url' => Crypt::decryptString($setting->discord_webhook_url),

                        'discord_notifications_deployments' => $setting->deployment_success_discord_notifications || $setting->deployment_failure_discord_notifications,
                        'discord_notifications_status_changes' => $setting->status_change_discord_notifications,
                        'discord_notifications_database_backups' => $setting->backup_success_discord_notifications || $setting->backup_failure_discord_notifications,
                        'discord_notifications_scheduled_tasks' => $setting->scheduled_task_success_discord_notifications || $setting->scheduled_task_failure_discord_notifications,
                        'discord_notifications_server_disk_usage' => $setting->server_disk_usage_discord_notifications,
                    ]);
            } catch (Exception $e) {
                \Log::error('Error migrating discord notification settings from teams table: '.$e->getMessage());
            }
        }
    }
};
