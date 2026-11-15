<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deletes the executions of schedules that no longer exist, and deletes the executions with their schedule from now
     * on, as for volume backups.
     */
    public function up(): void
    {
        DB::table('scheduled_database_backup_executions')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('scheduled_database_backups')
                ->whereColumn('scheduled_database_backups.id', 'scheduled_database_backup_executions.scheduled_database_backup_id'))
            ->delete();

        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->foreign('scheduled_database_backup_id', 'db_backup_executions_backup_fk')
                ->references('id')
                ->on('scheduled_database_backups')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->dropForeign('db_backup_executions_backup_fk');
        });
    }
};
