<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->renameColumn('number_of_backups_locally', 'database_backup_retention_amount_locally');
            $table->integer('database_backup_retention_amount_locally')->default(0)->nullable(false)->change();
            $table->integer('database_backup_retention_days_locally')->default(0)->nullable(false);
            $table->decimal('database_backup_retention_max_storage_locally', 17, 7)->default(0)->nullable(false);

            $table->integer('database_backup_retention_amount_s3')->default(0)->nullable(false);
            $table->integer('database_backup_retention_days_s3')->default(0)->nullable(false);
            $table->decimal('database_backup_retention_max_storage_s3', 17, 7)->default(0)->nullable(false);
        });
    }

    public function down()
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->renameColumn('database_backup_retention_amount_locally', 'number_of_backups_locally')->nullable(true)->change();
            $table->dropColumn([
                'database_backup_retention_days_locally',
                'database_backup_retention_max_storage_locally',
                'database_backup_retention_amount_s3',
                'database_backup_retention_days_s3',
                'database_backup_retention_max_storage_s3',
            ]);
        });
    }
};
