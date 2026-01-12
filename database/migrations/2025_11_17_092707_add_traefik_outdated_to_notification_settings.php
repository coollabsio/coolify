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
        Schema::table('discord_notification_settings', function (Blueprint $table) {
            $table->boolean('traefik_outdated_discord_notifications')->default(true);
        });

        Schema::table('slack_notification_settings', function (Blueprint $table) {
            $table->boolean('traefik_outdated_slack_notifications')->default(true);
        });

        // Only add if table exists and column doesn't exist
        if (Schema::hasTable('webhook_notification_settings') &&
            ! Schema::hasColumn('webhook_notification_settings', 'traefik_outdated_webhook_notifications')) {
            Schema::table('webhook_notification_settings', function (Blueprint $table) {
                $table->boolean('traefik_outdated_webhook_notifications')->default(true);
            });
        }

        Schema::table('telegram_notification_settings', function (Blueprint $table) {
            $table->boolean('traefik_outdated_telegram_notifications')->default(true);
        });

        Schema::table('pushover_notification_settings', function (Blueprint $table) {
            $table->boolean('traefik_outdated_pushover_notifications')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discord_notification_settings', function (Blueprint $table) {
            $table->dropColumn('traefik_outdated_discord_notifications');
        });

        Schema::table('slack_notification_settings', function (Blueprint $table) {
            $table->dropColumn('traefik_outdated_slack_notifications');
        });

        // Only drop if table and column exist
        if (Schema::hasTable('webhook_notification_settings') &&
            Schema::hasColumn('webhook_notification_settings', 'traefik_outdated_webhook_notifications')) {
            Schema::table('webhook_notification_settings', function (Blueprint $table) {
                $table->dropColumn('traefik_outdated_webhook_notifications');
            });
        }

        Schema::table('telegram_notification_settings', function (Blueprint $table) {
            $table->dropColumn('traefik_outdated_telegram_notifications');
        });

        Schema::table('pushover_notification_settings', function (Blueprint $table) {
            $table->dropColumn('traefik_outdated_pushover_notifications');
        });
    }
};
