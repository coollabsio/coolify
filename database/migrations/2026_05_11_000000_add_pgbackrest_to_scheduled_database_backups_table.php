<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            if (! Schema::hasColumn('scheduled_database_backups', 'backup_method')) {
                $table->string('backup_method')->default('dump')->after('save_s3');
            }
            if (! Schema::hasColumn('scheduled_database_backups', 'pgbackrest_backup_type')) {
                $table->string('pgbackrest_backup_type')->default('incr')->after('backup_method');
            }
            if (! Schema::hasColumn('scheduled_database_backups', 'pgbackrest_require_wal_archive')) {
                $table->boolean('pgbackrest_require_wal_archive')->default(true)->after('pgbackrest_backup_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            if (Schema::hasColumn('scheduled_database_backups', 'pgbackrest_require_wal_archive')) {
                $table->dropColumn('pgbackrest_require_wal_archive');
            }
            if (Schema::hasColumn('scheduled_database_backups', 'pgbackrest_backup_type')) {
                $table->dropColumn('pgbackrest_backup_type');
            }
            if (Schema::hasColumn('scheduled_database_backups', 'backup_method')) {
                $table->dropColumn('backup_method');
            }
        });
    }
};
