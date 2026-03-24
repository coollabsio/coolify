<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->string('engine')->default('dump')->after('enabled');
            $table->string('backup_type')->default('full')->after('engine');
        });

        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->boolean('pgbackrest_enabled')->default(false)->after('dump_all');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn(['engine', 'backup_type']);
        });

        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropColumn('pgbackrest_enabled');
        });
    }
};
