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
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->boolean('is_pgbackrest_enabled')->default(false);
            $table->boolean('pgbackrest_restart_required')->default(false);
        });

        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->string('backup_type')->default('pg_dump');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropColumn(['is_pgbackrest_enabled', 'pgbackrest_restart_required']);
        });

        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn('backup_type');
        });
    }
};
