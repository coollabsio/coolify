<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->string('backup_engine')->default('dump')->after('team_id');
            $table->string('pgbackrest_stanza')->nullable()->after('backup_engine');
            $table->string('pgbackrest_backup_type')->default('full')->after('pgbackrest_stanza');
            $table->string('pgbackrest_repo_type')->default('posix')->after('pgbackrest_backup_type');
            $table->string('pgbackrest_s3_bucket')->nullable()->after('pgbackrest_repo_type');
            $table->string('pgbackrest_s3_endpoint')->nullable()->after('pgbackrest_s3_bucket');
            $table->string('pgbackrest_s3_region')->default('us-east-1')->after('pgbackrest_s3_endpoint');
            $table->text('pgbackrest_s3_key')->nullable()->after('pgbackrest_s3_region');
            $table->text('pgbackrest_s3_secret')->nullable()->after('pgbackrest_s3_key');
            $table->string('pgbackrest_compress_type')->default('gz')->after('pgbackrest_s3_secret');
            $table->integer('pgbackrest_compress_level')->default(6)->after('pgbackrest_compress_type');
            $table->integer('pgbackrest_retention_full')->default(2)->after('pgbackrest_compress_level');
            $table->integer('pgbackrest_retention_diff')->default(7)->after('pgbackrest_retention_full');
            $table->string('pgbackrest_log_level_console')->default('warn')->after('pgbackrest_retention_diff');
            $table->string('pgbackrest_log_level_file')->default('info')->after('pgbackrest_log_level_console');
        });

        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->string('backup_engine')->default('dump')->after('scheduled_database_backup_id');
            $table->string('pgbackrest_backup_type')->nullable()->after('backup_engine');
            $table->string('pgbackrest_backup_label')->nullable()->after('pgbackrest_backup_type');
        });

        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->boolean('pgbackrest_enabled')->default(false)->after('custom_docker_run_options');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn([
                'backup_engine',
                'pgbackrest_stanza',
                'pgbackrest_backup_type',
                'pgbackrest_repo_type',
                'pgbackrest_s3_bucket',
                'pgbackrest_s3_endpoint',
                'pgbackrest_s3_region',
                'pgbackrest_s3_key',
                'pgbackrest_s3_secret',
                'pgbackrest_compress_type',
                'pgbackrest_compress_level',
                'pgbackrest_retention_full',
                'pgbackrest_retention_diff',
                'pgbackrest_log_level_console',
                'pgbackrest_log_level_file',
            ]);
        });

        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->dropColumn([
                'backup_engine',
                'pgbackrest_backup_type',
                'pgbackrest_backup_label',
            ]);
        });

        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropColumn('pgbackrest_enabled');
        });
    }
};
