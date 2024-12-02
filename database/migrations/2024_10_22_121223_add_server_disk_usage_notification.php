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
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('discord_notifications_server_disk_usage')->default(true)->after('discord_enabled');
            $table->boolean('smtp_notifications_server_disk_usage')->default(true)->after('smtp_enabled');
            $table->boolean('telegram_notifications_server_disk_usage')->default(true)->after('telegram_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('discord_notifications_server_disk_usage');
            $table->dropColumn('smtp_notifications_server_disk_usage');
            $table->dropColumn('telegram_notifications_server_disk_usage');
        });
    }
};
