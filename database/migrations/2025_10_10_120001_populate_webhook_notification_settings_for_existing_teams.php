<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $teams = DB::table('teams')->get();

        foreach ($teams as $team) {
            DB::table('webhook_notification_settings')->updateOrInsert(
                ['team_id' => $team->id],
                [
                    'webhook_enabled' => false,
                    'webhook_url' => null,
                    'deployment_success_webhook_notifications' => false,
                    'deployment_failure_webhook_notifications' => true,
                    'status_change_webhook_notifications' => false,
                    'backup_success_webhook_notifications' => false,
                    'backup_failure_webhook_notifications' => true,
                    'scheduled_task_success_webhook_notifications' => false,
                    'scheduled_task_failure_webhook_notifications' => true,
                    'docker_cleanup_success_webhook_notifications' => false,
                    'docker_cleanup_failure_webhook_notifications' => true,
                    'server_disk_usage_webhook_notifications' => true,
                    'server_reachable_webhook_notifications' => false,
                    'server_unreachable_webhook_notifications' => true,
                    'server_patch_webhook_notifications' => false,
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // We don't need to do anything in down() since the webhook_notification_settings
        // table will be dropped by the create migration's down() method
    }
};
