<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('smtp_notifications_database_backups')->default(true);
            $table->boolean('discord_notifications_database_backups')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('smtp_notifications_database_backups');
            $table->dropColumn('discord_notifications_database_backups');
        });
    }
};
