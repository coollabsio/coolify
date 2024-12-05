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
            $team->emailNotificationSettings()->updateOrCreate(
                ['team_id' => $team->id],
                [
                    'smtp_enabled' => $team->smtp_enabled ?? false,
                    'smtp_from_address' => $team->smtp_from_address,
                    'smtp_from_name' => $team->smtp_from_name,
                    'smtp_recipients' => $team->smtp_recipients,
                    'smtp_host' => $team->smtp_host,
                    'smtp_port' => $team->smtp_port,
                    'smtp_encryption' => $team->smtp_encryption,
                    'smtp_username' => $team->smtp_username,
                    'smtp_password' => $team->smtp_password,
                    'smtp_timeout' => $team->smtp_timeout,

                    'use_instance_email_settings' => $team->use_instance_email_settings ?? false,

                    'resend_enabled' => $team->resend_enabled ?? false,
                    'resend_api_key' => $team->resend_api_key,

                    'deployment_success_email_notifications' => $team->smtp_notifications_deployments ?? false,
                    'deployment_failure_email_notifications' => $team->smtp_notifications_deployments ?? true,
                    'backup_success_email_notifications' => $team->smtp_notifications_database_backups ?? false,
                    'backup_failure_email_notifications' => $team->smtp_notifications_database_backups ?? true,
                    'scheduled_task_success_email_notifications' => $team->smtp_notifications_scheduled_tasks ?? false,
                    'scheduled_task_failure_email_notifications' => $team->smtp_notifications_scheduled_tasks ?? true,
                    'status_change_email_notifications' => $team->smtp_notifications_status_changes ?? false,
                    'server_disk_usage_email_notifications' => true,
                ]
            );
        }

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'smtp_enabled',
                'smtp_from_address',
                'smtp_from_name',
                'smtp_recipients',
                'smtp_host',
                'smtp_port',
                'smtp_encryption',
                'smtp_username',
                'smtp_password',
                'smtp_timeout',
                'use_instance_email_settings',
                'resend_enabled',
                'resend_api_key',
                'smtp_notifications_test',
                'smtp_notifications_deployments',
                'smtp_notifications_database_backups',
                'smtp_notifications_scheduled_tasks',
                'smtp_notifications_status_changes',
                'smtp_notifications_server_disk_usage',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
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
            $table->boolean('resend_enabled')->default(false);
            $table->text('resend_api_key')->nullable();
            $table->boolean('smtp_notifications_deployments')->default(false);
            $table->boolean('smtp_notifications_database_backups')->default(true);
            $table->boolean('smtp_notifications_scheduled_tasks')->default(false);
            $table->boolean('smtp_notifications_status_changes')->default(false);
        });

        $teams = Team::with('emailNotificationSettings')->get();
        foreach ($teams as $team) {
            if ($settings = $team->emailNotificationSettings) {
                $team->update([
                    'smtp_enabled' => $settings->smtp_enabled,
                    'smtp_from_address' => $settings->smtp_from_address,
                    'smtp_from_name' => $settings->smtp_from_name,
                    'smtp_recipients' => $settings->smtp_recipients,
                    'smtp_host' => $settings->smtp_host,
                    'smtp_port' => $settings->smtp_port,
                    'smtp_encryption' => $settings->smtp_encryption,
                    'smtp_username' => $settings->smtp_username,
                    'smtp_password' => $settings->smtp_password,
                    'smtp_timeout' => $settings->smtp_timeout,
                    'use_instance_email_settings' => $settings->use_instance_email_settings,
                    'resend_enabled' => $settings->resend_enabled,
                    'resend_api_key' => $settings->resend_api_key,
                    'smtp_notifications_deployments' => $settings->deployment_success_email_notifications || $settings->deployment_failure_email_notifications,
                    'smtp_notifications_database_backups' => $settings->backup_success_email_notifications || $settings->backup_failure_email_notifications,
                    'smtp_notifications_scheduled_tasks' => $settings->scheduled_task_success_email_notifications || $settings->scheduled_task_failure_email_notifications,
                    'smtp_notifications_status_changes' => $settings->status_change_email_notifications,
                    'smtp_notifications_server_disk_usage' => $settings->server_disk_usage_email_notifications,
                ]);
            }
        }
    }
};
