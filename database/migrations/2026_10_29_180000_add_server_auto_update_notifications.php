<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANNELS = ['email', 'discord', 'slack', 'telegram', 'pushover', 'webhook'];

    public function up(): void
    {
        foreach (self::CHANNELS as $channel) {
            $column = "server_auto_update_{$channel}_notifications";
            if (! Schema::hasColumn("{$channel}_notification_settings", $column)) {
                Schema::table("{$channel}_notification_settings", function (Blueprint $table) use ($column) {
                    $table->boolean($column)->default(true);
                });
            }
        }

        if (! Schema::hasColumn('telegram_notification_settings', 'telegram_notifications_server_auto_update_thread_id')) {
            Schema::table('telegram_notification_settings', function (Blueprint $table) {
                $table->text('telegram_notifications_server_auto_update_thread_id')->nullable();
            });
        }

        if (! Schema::hasColumn('server_settings', 'auto_security_updates_last_run')) {
            Schema::table('server_settings', function (Blueprint $table) {
                $table->json('auto_security_updates_last_run')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::CHANNELS as $channel) {
            Schema::table("{$channel}_notification_settings", function (Blueprint $table) use ($channel) {
                $table->dropColumn("server_auto_update_{$channel}_notifications");
            });
        }

        Schema::table('telegram_notification_settings', function (Blueprint $table) {
            $table->dropColumn('telegram_notifications_server_auto_update_thread_id');
        });

        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn('auto_security_updates_last_run');
        });
    }
};
