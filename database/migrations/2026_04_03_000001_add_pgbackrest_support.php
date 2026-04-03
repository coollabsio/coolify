<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->string('engine')->default('pg_dump')->after('dump_all');
            $table->string('pgbackrest_backup_type')->default('full')->after('engine');
        });

        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->boolean('pgbackrest_enabled')->default(false)->after('postgres_conf');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn(['engine', 'pgbackrest_backup_type']);
        });

        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropColumn('pgbackrest_enabled');
        });
    }
};
