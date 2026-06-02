<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discord_notification_settings', function (Blueprint $table) {
            $table->string('discord_ping_type')->default('here');
            $table->text('discord_custom_ping_text')->nullable();
        });

        DB::table('discord_notification_settings')
            ->where('discord_ping_enabled', false)
            ->update(['discord_ping_type' => 'none']);

        Schema::table('discord_notification_settings', function (Blueprint $table) {
            $table->dropColumn('discord_ping_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('discord_notification_settings', function (Blueprint $table) {
            $table->boolean('discord_ping_enabled')->default(true);
        });

        DB::table('discord_notification_settings')
            ->where('discord_ping_type', 'none')
            ->update(['discord_ping_enabled' => false]);

        Schema::table('discord_notification_settings', function (Blueprint $table) {
            $table->dropColumn(['discord_ping_type', 'discord_custom_ping_text']);
        });
    }
};
