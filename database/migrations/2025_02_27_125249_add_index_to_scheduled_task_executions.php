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
        Schema::table('scheduled_task_executions', function (Blueprint $table) {
            $table->index(['scheduled_task_id', 'created_at'], 'scheduled_task_executions_task_id_created_at_index');
        });

        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->index(
                ['scheduled_database_backup_id', 'created_at'],
                'scheduled_db_backup_executions_backup_id_created_at_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_task_executions', function (Blueprint $table) {
            $table->dropIndex('scheduled_task_executions_task_id_created_at_index');
        });
        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->dropIndex('scheduled_db_backup_executions_backup_id_created_at_index');
        });
    }
};
