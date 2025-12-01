<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->boolean('use_pgbackrest')->default(false)->after('dump_all');
            $table->string('pgbackrest_backup_type')->default('full')->after('use_pgbackrest');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn([
                'use_pgbackrest',
                'pgbackrest_backup_type',
            ]);
        });
    }
};
