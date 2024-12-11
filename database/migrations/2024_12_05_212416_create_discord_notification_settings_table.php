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
        Schema::create('discord_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->boolean('discord_enabled')->default(false);
            $table->text('discord_webhook_url')->nullable();

            $table->boolean('deployment_success_discord_notifications')->default(false);
            $table->boolean('deployment_failure_discord_notifications')->default(true);
            $table->boolean('status_change_discord_notifications')->default(false);
            $table->boolean('backup_success_discord_notifications')->default(false);
            $table->boolean('backup_failure_discord_notifications')->default(true);
            $table->boolean('scheduled_task_success_discord_notifications')->default(false);
            $table->boolean('scheduled_task_failure_discord_notifications')->default(true);
            $table->boolean('docker_cleanup_success_discord_notifications')->default(false);
            $table->boolean('docker_cleanup_failure_discord_notifications')->default(true);
            $table->boolean('server_disk_usage_discord_notifications')->default(true);
            $table->boolean('server_reachable_discord_notifications')->default(false);
            $table->boolean('server_unreachable_discord_notifications')->default(true);

            $table->unique(['team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discord_notification_settings');
    }
};
