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

            // SMTP Configuration
            $table->boolean('smtp_enabled')->default(false);
            $table->string('smtp_from_address')->nullable();
            $table->string('smtp_from_name')->nullable();
            $table->string('smtp_recipients')->nullable();
            $table->string('smtp_host')->nullable();
            $table->integer('smtp_port')->nullable();
            $table->string('smtp_encryption')->nullable();
            $table->text('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->integer('smtp_timeout')->nullable();

            $table->boolean('use_instance_email_settings')->default(false);

            // Resend Configuration
            $table->boolean('resend_enabled')->default(false);
            $table->encryptedText('resend_api_key')->nullable();

            // Notification Settings
            $table->boolean('deployment_success_email_notification')->default(false);
            $table->boolean('deployment_failure_email_notification')->default(true);
            $table->boolean('backup_success_email_notification')->default(false);
            $table->boolean('backup_failure_email_notification')->default(true);
            $table->boolean('scheduled_task_success_email_notification')->default(false);
            $table->boolean('scheduled_task_failure_email_notification')->default(true);
            $table->boolean('status_change_email_notification')->default(false);
            $table->boolean('server_disk_usage_email_notification')->default(true);

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