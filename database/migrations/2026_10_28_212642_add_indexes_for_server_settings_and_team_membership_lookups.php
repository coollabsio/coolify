<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_server_settings_server_id ON server_settings (server_id)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_team_user_user_id ON team_user (user_id)');

            return;
        }

        if (! Schema::hasIndex('server_settings', 'idx_server_settings_server_id')) {
            Schema::table('server_settings', function (Blueprint $table) {
                $table->index('server_id', 'idx_server_settings_server_id');
            });
        }

        if (! Schema::hasIndex('team_user', 'idx_team_user_user_id')) {
            Schema::table('team_user', function (Blueprint $table) {
                $table->index('user_id', 'idx_team_user_user_id');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_team_user_user_id');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_server_settings_server_id');

            return;
        }

        if (Schema::hasIndex('team_user', 'idx_team_user_user_id')) {
            Schema::table('team_user', function (Blueprint $table) {
                $table->dropIndex('idx_team_user_user_id');
            });
        }

        if (Schema::hasIndex('server_settings', 'idx_server_settings_server_id')) {
            Schema::table('server_settings', function (Blueprint $table) {
                $table->dropIndex('idx_server_settings_server_id');
            });
        }
    }
};
