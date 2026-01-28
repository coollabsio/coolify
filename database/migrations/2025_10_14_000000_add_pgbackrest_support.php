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
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->string('backup_engine')->default('pg_dump')->after('database_type');
            $table->string('backup_type')->default('full')->after('backup_engine');
        });

        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->boolean('pgbackrest_enabled')->default(false)->after('custom_docker_run_options');
            $table->string('pgbackrest_stanza')->nullable()->after('pgbackrest_enabled');
            $table->string('pgbackrest_repo_type')->default('posix')->after('pgbackrest_stanza');
            $table->unsignedBigInteger('pgbackrest_s3_storage_id')->nullable()->after('pgbackrest_repo_type');
            $table->integer('pgbackrest_retention_full')->default(2)->after('pgbackrest_s3_storage_id');
            $table->integer('pgbackrest_retention_diff')->default(7)->after('pgbackrest_retention_full');

            $table->foreign('pgbackrest_s3_storage_id')
                ->references('id')
                ->on('s3_storages')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn(['backup_engine', 'backup_type']);
        });

        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropForeign(['pgbackrest_s3_storage_id']);
            $table->dropColumn([
                'pgbackrest_enabled',
                'pgbackrest_stanza',
                'pgbackrest_repo_type',
                'pgbackrest_s3_storage_id',
                'pgbackrest_retention_full',
                'pgbackrest_retention_diff',
            ]);
        });
    }
};
