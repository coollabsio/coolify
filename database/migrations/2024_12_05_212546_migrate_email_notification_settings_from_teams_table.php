<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $teams = DB::table('teams')->get();

        foreach ($teams as $team) {
            try {
                DB::table('email_notification_settings')->updateOrInsert(
                    ['team_id' => $team->id],
                    [
                        'smtp_enabled' => $team->smtp_enabled ?? false,
                        'smtp_from_address' => $team->smtp_from_address ? Crypt::encryptString($team->smtp_from_address) : null,
                        'smtp_from_name' => $team->smtp_from_name ? Crypt::encryptString($team->smtp_from_name) : null,
                        'smtp_recipients' => $team->smtp_recipients ? Crypt::encryptString($team->smtp_recipients) : null,
                        'smtp_host' => $team->smtp_host ? Crypt::encryptString($team->smtp_host) : null,
                        'smtp_port' => $team->smtp_port,
                        'smtp_encryption' => $team->smtp_encryption,
                        'smtp_username' => $team->smtp_username ? Crypt::encryptString($team->smtp_username) : null,
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
                        'server_disk_usage_email_notifications' => $team->smtp_notifications_server_disk_usage ?? true,
                    ]
                );
            } catch (Exception $e) {
                \Log::error('Error migrating email notification settings from teams table: '.$e->getMessage());
            }
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

            $table->boolean('smtp_notifications_test')->default(false);
            $table->boolean('smtp_notifications_deployments')->default(false);
            $table->boolean('smtp_notifications_database_backups')->default(true);
            $table->boolean('smtp_notifications_scheduled_tasks')->default(false);
            $table->boolean('smtp_notifications_status_changes')->default(false);
            $table->boolean('smtp_notifications_server_disk_usage')->default(true);
        });

        $settings = DB::table('email_notification_settings')->get();
        foreach ($settings as $setting) {
            try {
                DB::table('teams')
                    ->where('id', $setting->team_id)
                    ->update([
                        'smtp_enabled' => $setting->smtp_enabled,
                        'smtp_from_address' => $setting->smtp_from_address ? Crypt::decryptString($setting->smtp_from_address) : null,
                        'smtp_from_name' => $setting->smtp_from_name ? Crypt::decryptString($setting->smtp_from_name) : null,
                        'smtp_recipients' => $setting->smtp_recipients ? Crypt::decryptString($setting->smtp_recipients) : null,
                        'smtp_host' => $setting->smtp_host ? Crypt::decryptString($setting->smtp_host) : null,
                        'smtp_port' => $setting->smtp_port,
                        'smtp_encryption' => $setting->smtp_encryption,
                        'smtp_username' => $setting->smtp_username ? Crypt::decryptString($setting->smtp_username) : null,
                        'smtp_password' => $setting->smtp_password,
                        'smtp_timeout' => $setting->smtp_timeout,

                        'use_instance_email_settings' => $setting->use_instance_email_settings,

                        'resend_enabled' => $setting->resend_enabled,
                        'resend_api_key' => $setting->resend_api_key,

                        'smtp_notifications_deployments' => $setting->deployment_success_email_notifications || $setting->deployment_failure_email_notifications,
                        'smtp_notifications_database_backups' => $setting->backup_success_email_notifications || $setting->backup_failure_email_notifications,
                        'smtp_notifications_scheduled_tasks' => $setting->scheduled_task_success_email_notifications || $setting->scheduled_task_failure_email_notifications,
                        'smtp_notifications_status_changes' => $setting->status_change_email_notifications,
                    ]);
            } catch (Exception $e) {
                \Log::error('Error migrating email notification settings from teams table: '.$e->getMessage());
            }
        }
    }
};
