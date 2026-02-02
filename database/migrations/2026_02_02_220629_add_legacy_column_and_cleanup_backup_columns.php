<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->boolean('legacy')->default(false)->after('filename');
        });

        // Mark all existing backup executions as legacy
        DB::table('scheduled_database_backup_executions')->update(['legacy' => true]);

        // @todo: In a future update or v5 we no longer need "databases_to_backup", "dump_all" in "scheduled_database_backups" - keeping them for now if we need to rollback.
        // @todo: In a future update or v5 we no longer need "database_name" from "scheduled_database_backup_executions" - keeping them for now if we need to rollback.
    }
};
