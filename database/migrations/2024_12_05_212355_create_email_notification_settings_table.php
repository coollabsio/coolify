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
        Schema::create('email_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->boolean('smtp_enabled')->default(false);
            $table->text('smtp_from_address')->nullable();
            $table->text('smtp_from_name')->nullable();
            $table->text('smtp_recipients')->nullable();
            $table->text('smtp_host')->nullable();
            $table->integer('smtp_port')->nullable();
            $table->string('smtp_encryption')->nullable();
            $table->text('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->integer('smtp_timeout')->nullable();

            $table->boolean('resend_enabled')->default(false);
            $table->text('resend_api_key')->nullable();

            $table->boolean('use_instance_email_settings')->default(false);

            $table->boolean('deployment_success_email_notifications')->default(false);
            $table->boolean('deployment_failure_email_notifications')->default(true);
            $table->boolean('status_change_email_notifications')->default(false);
            $table->boolean('backup_success_email_notifications')->default(false);
            $table->boolean('backup_failure_email_notifications')->default(true);
            $table->boolean('scheduled_task_success_email_notifications')->default(false);
            $table->boolean('scheduled_task_failure_email_notifications')->default(true);
            $table->boolean('docker_cleanup_success_email_notifications')->default(false);
            $table->boolean('docker_cleanup_failure_email_notifications')->default(true);
            $table->boolean('server_disk_usage_email_notifications')->default(true);
            $table->boolean('server_reachable_email_notifications')->default(false);
            $table->boolean('server_unreachable_email_notifications')->default(true);

            $table->unique(['team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_notification_settings');
    }
};
