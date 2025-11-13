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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discord_notification_settings', function (Blueprint $table) {
            $table->dropColumn('traefik_outdated_discord_notifications');
        });
    }
};
