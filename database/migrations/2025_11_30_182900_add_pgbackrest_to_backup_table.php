<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->string('postgres_backup_tool')->default('pgbackrest')->after('dump_all');
            $table->string('pgbackrest_backup_type')->default('incr')->after('postgres_backup_tool');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn('postgres_backup_tool');
            $table->dropColumn('pgbackrest_backup_type');
        });
    }
};
