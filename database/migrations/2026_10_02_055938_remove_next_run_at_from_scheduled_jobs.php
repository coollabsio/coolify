<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Undo the removed migration 2026_09_28_194652_add_next_run_at_to_scheduled_jobs where it ran.
     * Environments that never ran it are not changed.
     */
    public function up(): void
    {
        foreach (['scheduled_database_backups', 'scheduled_tasks', 'scheduled_volume_backups'] as $tableName) {
            if (Schema::hasColumn($tableName, 'next_run_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropIndex(['enabled', 'next_run_at']);
                    $table->dropColumn('next_run_at');
                });
            }
        }

        if (Schema::hasColumn('server_settings', 'docker_cleanup_next_run_at')) {
            Schema::table('server_settings', function (Blueprint $table) {
                $table->dropIndex(['docker_cleanup_next_run_at']);
                $table->dropColumn('docker_cleanup_next_run_at');
            });
        }

        if (! Schema::hasTable('scheduled_job_states')) {
            Schema::create('scheduled_job_states', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('schedule_key')->unique();
                $table->timestampTz('last_scheduled_for')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // The removed columns are not added again.
    }
};
