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
        Schema::create('webhook_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->boolean('webhook_enabled')->default(false);
            $table->text('webhook_url')->nullable();

            $table->boolean('deployment_success_webhook_notifications')->default(false);
            $table->boolean('deployment_failure_webhook_notifications')->default(true);
            $table->boolean('status_change_webhook_notifications')->default(false);
            $table->boolean('backup_success_webhook_notifications')->default(false);
            $table->boolean('backup_failure_webhook_notifications')->default(true);
            $table->boolean('scheduled_task_success_webhook_notifications')->default(false);
            $table->boolean('scheduled_task_failure_webhook_notifications')->default(true);
            $table->boolean('docker_cleanup_success_webhook_notifications')->default(false);
            $table->boolean('docker_cleanup_failure_webhook_notifications')->default(true);
            $table->boolean('server_disk_usage_webhook_notifications')->default(true);
            $table->boolean('server_reachable_webhook_notifications')->default(false);
            $table->boolean('server_unreachable_webhook_notifications')->default(true);
            $table->boolean('server_patch_webhook_notifications')->default(false);

            $table->unique(['team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_notification_settings');
    }
};
