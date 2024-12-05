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
            $table->string('discord_webhook_url')->nullable();

            // Notification Settings
            $table->boolean('deployment_success_discord_notification')->default(false);
            $table->boolean('deployment_failure_discord_notification')->default(true);
            $table->boolean('backup_success_discord_notification')->default(false);
            $table->boolean('backup_failure_discord_notification')->default(true);
            $table->boolean('scheduled_task_success_discord_notification')->default(false);
            $table->boolean('scheduled_task_failure_discord_notification')->default(true);
            $table->boolean('status_change_discord_notification')->default(false);
            $table->boolean('server_disk_usage_discord_notification')->default(true);

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