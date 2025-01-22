<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            DB::table('application_deployment_queues')
                ->whereNull('finished_at')
                ->update(['finished_at' => DB::raw('updated_at')]);
        } catch (\Exception $e) {
            \Log::error('Failed to update not set finished_at timestamps for application_deployment_queues: '.$e->getMessage());
        }

        try {
            DB::table('scheduled_database_backup_executions')
                ->whereNull('finished_at')
                ->update(['finished_at' => DB::raw('updated_at')]);
        } catch (\Exception $e) {
            \Log::error('Failed to update not set finished_at timestamps for scheduled_database_backup_executions: '.$e->getMessage());
        }

        try {
            DB::table('scheduled_task_executions')
                ->whereNull('finished_at')
                ->update(['finished_at' => DB::raw('updated_at')]);
        } catch (\Exception $e) {
            \Log::error('Failed to update not set finished_at timestamps for scheduled_task_executions: '.$e->getMessage());
        }
    }
};
