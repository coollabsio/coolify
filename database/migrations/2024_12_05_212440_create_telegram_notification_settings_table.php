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
        Schema::create('telegram_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->boolean('telegram_enabled')->default(false);
            $table->text('telegram_token')->nullable();
            $table->text('telegram_chat_id')->nullable();

            $table->boolean('deployment_success_telegram_notifications')->default(false);
            $table->boolean('deployment_failure_telegram_notifications')->default(true);
            $table->boolean('status_change_telegram_notifications')->default(false);
            $table->boolean('backup_success_telegram_notifications')->default(false);
            $table->boolean('backup_failure_telegram_notifications')->default(true);
            $table->boolean('scheduled_task_success_telegram_notifications')->default(false);
            $table->boolean('scheduled_task_failure_telegram_notifications')->default(true);
            $table->boolean('docker_cleanup_success_telegram_notifications')->default(false);
            $table->boolean('docker_cleanup_failure_telegram_notifications')->default(true);
            $table->boolean('server_disk_usage_telegram_notifications')->default(true);
            $table->boolean('server_reachable_telegram_notifications')->default(false);
            $table->boolean('server_unreachable_telegram_notifications')->default(true);

            $table->text('telegram_notifications_deployment_success_thread_id')->nullable();
            $table->text('telegram_notifications_deployment_failure_thread_id')->nullable();
            $table->text('telegram_notifications_status_change_thread_id')->nullable();
            $table->text('telegram_notifications_backup_success_thread_id')->nullable();
            $table->text('telegram_notifications_backup_failure_thread_id')->nullable();
            $table->text('telegram_notifications_scheduled_task_success_thread_id')->nullable();
            $table->text('telegram_notifications_scheduled_task_failure_thread_id')->nullable();
            $table->text('telegram_notifications_docker_cleanup_success_thread_id')->nullable();
            $table->text('telegram_notifications_docker_cleanup_failure_thread_id')->nullable();
            $table->text('telegram_notifications_server_disk_usage_thread_id')->nullable();
            $table->text('telegram_notifications_server_reachable_thread_id')->nullable();
            $table->text('telegram_notifications_server_unreachable_thread_id')->nullable();

            $table->unique(['team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_notification_settings');
    }
};
