<?php

use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $teams = Team::all();

        foreach ($teams as $team) {

            $team->telegramNotificationSettings()->updateOrCreate(
                ['team_id' => $team->id],
                [
                    'telegram_enabled' => $team->telegram_enabled ?? false,
                    'telegram_token' => $team->telegram_token,
                    'telegram_chat_id' => $team->telegram_chat_id,

                    'deployment_success_telegram_notification' => $team->telegram_notifications_deployments ?? false,
                    'deployment_failure_telegram_notification' => $team->telegram_notifications_deployments ?? true,
                    'backup_success_telegram_notification' => $team->telegram_notifications_database_backups ?? false,
                    'backup_failure_telegram_notification' => $team->telegram_notifications_database_backups ?? true,
                    'scheduled_task_success_telegram_notification' => $team->telegram_notifications_scheduled_tasks ?? false,
                    'scheduled_task_failure_telegram_notification' => $team->telegram_notifications_scheduled_tasks ?? true,
                    'status_change_telegram_notification' => $team->telegram_notifications_status_changes ?? false,
                    'server_disk_usage_telegram_notification' => $team->telegram_notifications_server_disk_usage ?? true,

                    'telegram_notifications_deployment_success_thread_id' => $team->telegram_notifications_deployments_message_thread_id,
                    'telegram_notifications_deployment_failure_thread_id' => $team->telegram_notifications_deployments_message_thread_id,
                    'telegram_notifications_backup_success_thread_id' => $team->telegram_notifications_database_backups_message_thread_id,
                    'telegram_notifications_backup_failure_thread_id' => $team->telegram_notifications_database_backups_message_thread_id,
                    'telegram_notifications_scheduled_task_success_thread_id' => $team->telegram_notifications_scheduled_tasks_thread_id,
                    'telegram_notifications_scheduled_task_failure_thread_id' => $team->telegram_notifications_scheduled_tasks_thread_id,
                    'telegram_notifications_status_change_thread_id' => $team->telegram_notifications_status_changes_message_thread_id,
                ]
            );
        }

        // Drop the old columns
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
        // Add back the old columns
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

        // Migrate data back from the new table to the old columns
        $teams = Team::with('telegramNotificationSettings')->get();
        foreach ($teams as $team) {
            if ($settings = $team->telegramNotificationSettings) {
                $team->update([
                    'telegram_enabled' => $settings->telegram_enabled,
                    'telegram_token' => $settings->telegram_token,
                    'telegram_chat_id' => $settings->telegram_chat_id,
                    'telegram_notifications_test' => true,
                    'telegram_notifications_deployments' => $settings->deployment_success_telegram_notification,
                    'telegram_notifications_status_changes' => $settings->status_change_telegram_notification,
                    'telegram_notifications_database_backups' => $settings->backup_success_telegram_notification,
                    'telegram_notifications_scheduled_tasks' => $settings->scheduled_task_success_telegram_notification,
                    'telegram_notifications_server_disk_usage' => $settings->server_disk_usage_telegram_notification,
                    'telegram_notifications_deployments_message_thread_id' => $settings->telegram_notifications_deployment_success_thread_id,
                    'telegram_notifications_status_changes_message_thread_id' => $settings->telegram_notifications_status_change_thread_id,
                    'telegram_notifications_database_backups_message_thread_id' => $settings->telegram_notifications_backup_success_thread_id,
                    'telegram_notifications_scheduled_tasks_thread_id' => $settings->telegram_notifications_scheduled_task_success_thread_id,
                ]);
            }
        }
    }
};
