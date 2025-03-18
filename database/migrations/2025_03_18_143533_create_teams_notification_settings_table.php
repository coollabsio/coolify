<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('teams_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();

            $table->boolean('teams_enabled')->default(false);
            $table->text('teams_webhook_url')->nullable();

            $table->boolean('deployment_success_teams_notifications')->default(false);
            $table->boolean('deployment_failure_teams_notifications')->default(true);
            $table->boolean('status_change_teams_notifications')->default(false);
            $table->boolean('backup_success_teams_notifications')->default(false);
            $table->boolean('backup_failure_teams_notifications')->default(true);
            $table->boolean('scheduled_task_success_teams_notifications')->default(false);
            $table->boolean('scheduled_task_failure_teams_notifications')->default(true);
            $table->boolean('docker_cleanup_success_teams_notifications')->default(false);
            $table->boolean('docker_cleanup_failure_teams_notifications')->default(true);
            $table->boolean('server_disk_usage_teams_notifications')->default(true);
            $table->boolean('server_reachable_teams_notifications')->default(false);
            $table->boolean('server_unreachable_teams_notifications')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teams_notification_settings');
    }
};
