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
            $table->boolean('use_pgbackrest')->default(false)->after('dump_all');
            $table->string('pgbackrest_backup_type')->default('full')->after('use_pgbackrest');
            $table->integer('pgbackrest_retention_full')->default(2)->after('pgbackrest_backup_type');
            $table->integer('pgbackrest_retention_diff')->default(7)->after('pgbackrest_retention_full');
            $table->string('pgbackrest_repo_type')->default('posix')->after('pgbackrest_retention_diff');
            $table->string('pgbackrest_s3_bucket')->nullable()->after('pgbackrest_repo_type');
            $table->string('pgbackrest_s3_endpoint')->nullable()->after('pgbackrest_s3_bucket');
            $table->string('pgbackrest_s3_region')->default('us-east-1')->after('pgbackrest_s3_endpoint');
            $table->text('pgbackrest_s3_key')->nullable()->after('pgbackrest_s3_region');
            $table->text('pgbackrest_s3_secret')->nullable()->after('pgbackrest_s3_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn([
                'use_pgbackrest',
                'pgbackrest_backup_type',
                'pgbackrest_retention_full',
                'pgbackrest_retention_diff',
                'pgbackrest_repo_type',
                'pgbackrest_s3_bucket',
                'pgbackrest_s3_endpoint',
                'pgbackrest_s3_region',
                'pgbackrest_s3_key',
                'pgbackrest_s3_secret',
            ]);
        });
    }
};
