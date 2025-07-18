<?php

use App\Models\InstanceSettings;
use App\Models\Team;
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
            $table->boolean('smtp_notifications_test')->default(true);
            $table->boolean('smtp_notifications_deployments')->default(false);
            $table->boolean('smtp_notifications_status_changes')->default(false);

            $table->boolean('discord_enabled')->default(false);
            $table->string('discord_webhook_url')->nullable();
            $table->boolean('discord_notifications_test')->default(true);
            $table->boolean('discord_notifications_deployments')->default(true);
            $table->boolean('discord_notifications_status_changes')->default(true);
        });
        $teams = Team::all();
        foreach ($teams as $team) {
            $team->smtp_enabled = data_get($team, 'smtp.enabled', false);
            $team->smtp_from_address = data_get($team, 'smtp.from_address');
            $team->smtp_from_name = data_get($team, 'smtp.from_name');
            $team->smtp_recipients = data_get($team, 'smtp.recipients');
            $team->smtp_host = data_get($team, 'smtp.host');
            $team->smtp_port = data_get($team, 'smtp.port');
            $team->smtp_encryption = data_get($team, 'smtp.encryption');
            $team->smtp_username = data_get($team, 'smtp.username');
            $team->smtp_password = data_get($team, 'smtp.password');
            $team->smtp_timeout = data_get($team, 'smtp.timeout');
            $team->smtp_notifications_test = data_get($team, 'smtp_notifications.test', true);
            $team->smtp_notifications_deployments = data_get($team, 'smtp_notifications.deployments', false);
            $team->smtp_notifications_status_changes = data_get($team, 'smtp_notifications.status_changes', false);

            $team->discord_enabled = data_get($team, 'discord.enabled', false);
            $team->discord_webhook_url = data_get($team, 'discord.webhook_url');
            $team->discord_notifications_test = data_get($team, 'discord_notifications.test', true);
            $team->discord_notifications_deployments = data_get($team, 'discord_notifications.deployments', true);
            $team->discord_notifications_status_changes = data_get($team, 'discord_notifications.status_changes', true);

            $team->save();
        }
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('smtp');
            $table->dropColumn('smtp_notifications');
            $table->dropColumn('discord');
            $table->dropColumn('discord_notifications');
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('smtp_enabled')->default(false);
            $table->string('smtp_from_address')->nullable();
            $table->string('smtp_from_name')->nullable();
            $table->text('smtp_recipients')->nullable();
            $table->string('smtp_host')->nullable();
            $table->integer('smtp_port')->nullable();
            $table->string('smtp_encryption')->nullable();
            $table->text('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->integer('smtp_timeout')->nullable();
        });
        $instance_settings = InstanceSettings::all();
        foreach ($instance_settings as $instance_setting) {
            $instance_setting->smtp_enabled = data_get($instance_setting, 'smtp.enabled', false);
            $instance_setting->smtp_from_address = data_get($instance_setting, 'smtp.from_address');
            $instance_setting->smtp_from_name = data_get($instance_setting, 'smtp.from_name');
            $instance_setting->smtp_recipients = data_get($instance_setting, 'smtp.recipients');
            $instance_setting->smtp_host = data_get($instance_setting, 'smtp.host');
            $instance_setting->smtp_port = data_get($instance_setting, 'smtp.port');
            $instance_setting->smtp_encryption = data_get($instance_setting, 'smtp.encryption');
            $instance_setting->smtp_username = data_get($instance_setting, 'smtp.username');
            $instance_setting->smtp_password = data_get($instance_setting, 'smtp.password');
            $instance_setting->smtp_timeout = data_get($instance_setting, 'smtp.timeout');
            $instance_setting->save();
        }
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('smtp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->schemalessAttributes('smtp');
            $table->schemalessAttributes('smtp_notifications');
            $table->schemalessAttributes('discord');
            $table->schemalessAttributes('discord_notifications');
        });
        $teams = Team::all();
        foreach ($teams as $team) {
            $team->smtp = [
                'enabled' => $team->smtp_enabled,
                'from_address' => $team->smtp_from_address,
                'from_name' => $team->smtp_from_name,
                'recipients' => $team->smtp_recipients,
                'host' => $team->smtp_host,
                'port' => $team->smtp_port,
                'encryption' => $team->smtp_encryption,
                'username' => $team->smtp_username,
                'password' => $team->smtp_password,
                'timeout' => $team->smtp_timeout,
            ];
            $team->smtp_notifications = [
                'test' => $team->smtp_notifications_test,
                'deployments' => $team->smtp_notifications_deployments,
                'status_changes' => $team->smtp_notifications_status_changes,
            ];
            $team->discord = [
                'enabled' => $team->discord_enabled,
                'webhook_url' => $team->discord_webhook_url,
            ];
            $team->discord_notifications = [
                'test' => $team->discord_notifications_test,
                'deployments' => $team->discord_notifications_deployments,
                'status_changes' => $team->discord_notifications_status_changes,
            ];
            $team->save();
        }
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('smtp_enabled');
            $table->dropColumn('smtp_from_address');
            $table->dropColumn('smtp_from_name');
            $table->dropColumn('smtp_recipients');
            $table->dropColumn('smtp_host');
            $table->dropColumn('smtp_port');
            $table->dropColumn('smtp_encryption');
            $table->dropColumn('smtp_username');
            $table->dropColumn('smtp_password');
            $table->dropColumn('smtp_timeout');
            $table->dropColumn('smtp_notifications_test');
            $table->dropColumn('smtp_notifications_deployments');
            $table->dropColumn('smtp_notifications_status_changes');

            $table->dropColumn('discord_enabled');
            $table->dropColumn('discord_webhook_url');
            $table->dropColumn('discord_notifications_test');
            $table->dropColumn('discord_notifications_deployments');
            $table->dropColumn('discord_notifications_status_changes');
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->schemalessAttributes('smtp');
        });

        $instance_setting = instanceSettings();
        $instance_setting->smtp = [
            'enabled' => $instance_setting->smtp_enabled,
            'from_address' => $instance_setting->smtp_from_address,
            'from_name' => $instance_setting->smtp_from_name,
            'recipients' => $instance_setting->smtp_recipients,
            'host' => $instance_setting->smtp_host,
            'port' => $instance_setting->smtp_port,
            'encryption' => $instance_setting->smtp_encryption,
            'username' => $instance_setting->smtp_username,
            'password' => $instance_setting->smtp_password,
            'timeout' => $instance_setting->smtp_timeout,
        ];
        $instance_setting->save();
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('smtp_enabled');
            $table->dropColumn('smtp_from_address');
            $table->dropColumn('smtp_from_name');
            $table->dropColumn('smtp_recipients');
            $table->dropColumn('smtp_host');
            $table->dropColumn('smtp_port');
            $table->dropColumn('smtp_encryption');
            $table->dropColumn('smtp_username');
            $table->dropColumn('smtp_password');
            $table->dropColumn('smtp_timeout');
        });
    }
};
