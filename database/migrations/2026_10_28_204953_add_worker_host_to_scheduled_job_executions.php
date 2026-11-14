<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['scheduled_task_executions', 'scheduled_database_backup_executions', 'scheduled_volume_backup_executions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('worker_host')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('worker_host');
            });
        }
    }
};
