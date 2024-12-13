<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $teams = DB::table('teams')->get();

        foreach ($teams as $team) {
            try {
                DB::table('telegram_notification_settings')->updateOrInsert(
                    ['team_id' => $team->id],
                    [
                        'telegram_enabled' => $team->telegram_enabled ?? false,
                        'telegram_token' => $team->telegram_token ? Crypt::encryptString($team->telegram_token) : null,
                        'telegram_chat_id' => $team->telegram_chat_id ? Crypt::encryptString($team->telegram_chat_id) : null,

                        'deployment_success_telegram_notifications' => $team->telegram_notifications_deployments ?? false,
                        'deployment_failure_telegram_notifications' => $team->telegram_notifications_deployments ?? true,
                        'backup_success_telegram_notifications' => $team->telegram_notifications_database_backups ?? false,
                        'backup_failure_telegram_notifications' => $team->telegram_notifications_database_backups ?? true,
                        'scheduled_task_success_telegram_notifications' => $team->telegram_notifications_scheduled_tasks ?? false,
                        'scheduled_task_failure_telegram_notifications' => $team->telegram_notifications_scheduled_tasks ?? true,
                        'status_change_telegram_notifications' => $team->telegram_notifications_status_changes ?? false,
                        'server_disk_usage_telegram_notifications' => $team->telegram_notifications_server_disk_usage ?? true,

                        'telegram_notifications_deployment_success_thread_id' => $team->telegram_notifications_deployments_message_thread_id ? Crypt::encryptString($team->telegram_notifications_deployments_message_thread_id) : null,
                        'telegram_notifications_deployment_failure_thread_id' => $team->telegram_notifications_deployments_message_thread_id ? Crypt::encryptString($team->telegram_notifications_deployments_message_thread_id) : null,
                        'telegram_notifications_backup_success_thread_id' => $team->telegram_notifications_database_backups_message_thread_id ? Crypt::encryptString($team->telegram_notifications_database_backups_message_thread_id) : null,
                        'telegram_notifications_backup_failure_thread_id' => $team->telegram_notifications_database_backups_message_thread_id ? Crypt::encryptString($team->telegram_notifications_database_backups_message_thread_id) : null,
                        'telegram_notifications_scheduled_task_success_thread_id' => $team->telegram_notifications_scheduled_tasks_thread_id ? Crypt::encryptString($team->telegram_notifications_scheduled_tasks_thread_id) : null,
                        'telegram_notifications_scheduled_task_failure_thread_id' => $team->telegram_notifications_scheduled_tasks_thread_id ? Crypt::encryptString($team->telegram_notifications_scheduled_tasks_thread_id) : null,
                        'telegram_notifications_status_change_thread_id' => $team->telegram_notifications_status_changes_message_thread_id ? Crypt::encryptString($team->telegram_notifications_status_changes_message_thread_id) : null,
                    ]
                );
            } catch (Exception $e) {
                Log::error('Error migrating telegram notification settings from teams table: '.$e->getMessage());
            }
        }

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'telegram_enabled',
                'telegram_token',
                'telegram_chat_id',
                'telegram_notifications_test',
                'telegram_notifications_deployments',
                'telegram_notifications_status_changes',
                'telegram_notifications_database_backups',
                'telegram_notifications_scheduled_tasks',
                'telegram_notifications_server_disk_usage',
                'telegram_notifications_test_message_thread_id',
                'telegram_notifications_deployments_message_thread_id',
                'telegram_notifications_status_changes_message_thread_id',
                'telegram_notifications_database_backups_message_thread_id',
                'telegram_notifications_scheduled_tasks_thread_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('telegram_enabled')->default(false);
            $table->text('telegram_token')->nullable();
            $table->text('telegram_chat_id')->nullable();

            $table->boolean('telegram_notifications_test')->default(true);
            $table->boolean('telegram_notifications_deployments')->default(true);
            $table->boolean('telegram_notifications_status_changes')->default(true);
            $table->boolean('telegram_notifications_database_backups')->default(true);
            $table->boolean('telegram_notifications_scheduled_tasks')->default(true);
            $table->boolean('telegram_notifications_server_disk_usage')->default(true);

            $table->text('telegram_notifications_test_message_thread_id')->nullable();
            $table->text('telegram_notifications_deployments_message_thread_id')->nullable();
            $table->text('telegram_notifications_status_changes_message_thread_id')->nullable();
            $table->text('telegram_notifications_database_backups_message_thread_id')->nullable();
            $table->text('telegram_notifications_scheduled_tasks_thread_id')->nullable();
        });

        $settings = DB::table('telegram_notification_settings')->get();
        foreach ($settings as $setting) {
            try {
                DB::table('teams')
                    ->where('id', $setting->team_id)
                    ->update([
                        'telegram_enabled' => $setting->telegram_enabled,
                        'telegram_token' => $setting->telegram_token ? Crypt::decryptString($setting->telegram_token) : null,
                        'telegram_chat_id' => $setting->telegram_chat_id ? Crypt::decryptString($setting->telegram_chat_id) : null,

                        'telegram_notifications_deployments' => $setting->deployment_success_telegram_notifications || $setting->deployment_failure_telegram_notifications,
                        'telegram_notifications_status_changes' => $setting->status_change_telegram_notifications,
                        'telegram_notifications_database_backups' => $setting->backup_success_telegram_notifications || $setting->backup_failure_telegram_notifications,
                        'telegram_notifications_scheduled_tasks' => $setting->scheduled_task_success_telegram_notifications || $setting->scheduled_task_failure_telegram_notifications,
                        'telegram_notifications_server_disk_usage' => $setting->server_disk_usage_telegram_notifications,

                        'telegram_notifications_deployments_message_thread_id' => $setting->telegram_notifications_deployment_success_thread_id ? Crypt::decryptString($setting->telegram_notifications_deployment_success_thread_id) : null,
                        'telegram_notifications_status_changes_message_thread_id' => $setting->telegram_notifications_status_change_thread_id ? Crypt::decryptString($setting->telegram_notifications_status_change_thread_id) : null,
                        'telegram_notifications_database_backups_message_thread_id' => $setting->telegram_notifications_backup_success_thread_id ? Crypt::decryptString($setting->telegram_notifications_backup_success_thread_id) : null,
                        'telegram_notifications_scheduled_tasks_thread_id' => $setting->telegram_notifications_scheduled_task_success_thread_id ? Crypt::decryptString($setting->telegram_notifications_scheduled_task_success_thread_id) : null,
                    ]);
            } catch (Exception $e) {
                Log::error('Error migrating telegram notification settings from teams table: '.$e->getMessage());
            }
        }
    }
};
