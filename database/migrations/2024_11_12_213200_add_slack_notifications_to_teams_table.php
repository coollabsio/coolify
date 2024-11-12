<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('slack_enabled')->default(false);
            $table->string('slack_webhook_url')->nullable();
            $table->boolean('slack_notifications_test')->default(false);
            $table->boolean('slack_notifications_deployments')->default(false);
            $table->boolean('slack_notifications_status_changes')->default(false);
            $table->boolean('slack_notifications_database_backups')->default(false);
            $table->boolean('slack_notifications_scheduled_tasks')->default(false);
            $table->boolean('slack_notifications_server_disk_usage')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'slack_enabled',
                'slack_webhook_url',
                'slack_notifications_test',
                'slack_notifications_deployments',
                'slack_notifications_status_changes',
                'slack_notifications_database_backups',
                'slack_notifications_scheduled_tasks',
                'slack_notifications_server_disk_usage',
            ]);
        });
    }
};