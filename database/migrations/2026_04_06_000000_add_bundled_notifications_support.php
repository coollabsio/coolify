<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('servers', 'patch_check_data')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->json('patch_check_data')->nullable();
            });
        }

        $notificationTables = [
            'email_notification_settings',
            'discord_notification_settings',
            'slack_notification_settings',
            'telegram_notification_settings',
            'pushover_notification_settings',
            'webhook_notification_settings',
        ];

        foreach ($notificationTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'bundle_patch_notifications')) {
                    $table->boolean('bundle_patch_notifications')->default(false);
                }
                if (! Schema::hasColumn($tableName, 'bundle_traefik_notifications')) {
                    $table->boolean('bundle_traefik_notifications')->default(false);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('servers', 'patch_check_data')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->dropColumn('patch_check_data');
            });
        }

        $notificationTables = [
            'email_notification_settings',
            'discord_notification_settings',
            'slack_notification_settings',
            'telegram_notification_settings',
            'pushover_notification_settings',
            'webhook_notification_settings',
        ];

        foreach ($notificationTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'bundle_patch_notifications')) {
                    $table->dropColumn('bundle_patch_notifications');
                }
                if (Schema::hasColumn($tableName, 'bundle_traefik_notifications')) {
                    $table->dropColumn('bundle_traefik_notifications');
                }
            });
        }
    }
};
