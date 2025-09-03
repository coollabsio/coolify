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
        // Add server patch notification fields to email notification settings
        Schema::table('email_notification_settings', function (Blueprint $table) {
            $table->boolean('server_patch_email_notifications')->default(true);
        });

        // Add server patch notification fields to discord notification settings
        Schema::table('discord_notification_settings', function (Blueprint $table) {
            $table->boolean('server_patch_discord_notifications')->default(true);
        });

        // Add server patch notification fields to telegram notification settings
        Schema::table('telegram_notification_settings', function (Blueprint $table) {
            $table->boolean('server_patch_telegram_notifications')->default(true);
            $table->string('telegram_notifications_server_patch_thread_id')->nullable();
        });

        // Add server patch notification fields to slack notification settings
        Schema::table('slack_notification_settings', function (Blueprint $table) {
            $table->boolean('server_patch_slack_notifications')->default(true);
        });

        // Add server patch notification fields to pushover notification settings
        Schema::table('pushover_notification_settings', function (Blueprint $table) {
            $table->boolean('server_patch_pushover_notifications')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove server patch notification fields from email notification settings
        Schema::table('email_notification_settings', function (Blueprint $table) {
            $table->dropColumn('server_patch_email_notifications');
        });

        // Remove server patch notification fields from discord notification settings
        Schema::table('discord_notification_settings', function (Blueprint $table) {
            $table->dropColumn('server_patch_discord_notifications');
        });

        // Remove server patch notification fields from telegram notification settings
        Schema::table('telegram_notification_settings', function (Blueprint $table) {
            $table->dropColumn([
                'server_patch_telegram_notifications',
                'telegram_notifications_server_patch_thread_id',
            ]);
        });

        // Remove server patch notification fields from slack notification settings
        Schema::table('slack_notification_settings', function (Blueprint $table) {
            $table->dropColumn('server_patch_slack_notifications');
        });

        // Remove server patch notification fields from pushover notification settings
        Schema::table('pushover_notification_settings', function (Blueprint $table) {
            $table->dropColumn('server_patch_pushover_notifications');
        });
    }
};
