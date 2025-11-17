<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            // Backup method: pg_dump (default) or pgbackrest
            $table->string('backup_type')->default('pg_dump')->after('timeout');

            // pgBackRest specific settings
            $table->string('pgbackrest_type')->nullable()->after('backup_type'); // full, diff, incr
            $table->string('pgbackrest_stanza')->nullable()->after('pgbackrest_type');

            // pgBackRest retention settings (separate from legacy retention)
            $table->integer('pgbackrest_retention_full')->default(2)->nullable()->after('pgbackrest_stanza');
            $table->integer('pgbackrest_retention_diff')->nullable()->after('pgbackrest_retention_full');

            // Enable block-level incremental backup (pgBackRest 2.36+)
            $table->boolean('pgbackrest_block_incremental')->default(false)->after('pgbackrest_retention_diff');

            // Process max for parallel backup/restore
            $table->integer('pgbackrest_process_max')->default(1)->after('pgbackrest_block_incremental');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn([
                'backup_type',
                'pgbackrest_type',
                'pgbackrest_stanza',
                'pgbackrest_retention_full',
                'pgbackrest_retention_diff',
                'pgbackrest_block_incremental',
                'pgbackrest_process_max',
            ]);
        });
    }
};