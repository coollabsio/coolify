<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            // Full backup retention type: 'count' (default) or 'time' (days)
            $table->string('pgbackrest_retention_full_type')->default('count')->after('pgbackrest_retention_diff');

            // Archive (WAL) retention settings - critical for PITR capability
            $table->string('pgbackrest_retention_archive_type')->default('full')->after('pgbackrest_retention_full_type');
            $table->integer('pgbackrest_retention_archive')->nullable()->after('pgbackrest_retention_archive_type');

            // Restore tracking fields
            $table->string('pgbackrest_restore_status')->nullable()->after('pgbackrest_compress_level');
            $table->text('pgbackrest_restore_message')->nullable()->after('pgbackrest_restore_status');
            $table->timestamp('pgbackrest_restore_started_at')->nullable()->after('pgbackrest_restore_message');
        });
    }

    public function down(): void
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropColumn([
                'pgbackrest_retention_full_type',
                'pgbackrest_retention_archive_type',
                'pgbackrest_retention_archive',
                'pgbackrest_restore_status',
                'pgbackrest_restore_message',
                'pgbackrest_restore_started_at',
            ]);
        });
    }
};
