<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Supports DockerCleanupJob::failInterruptedExecutions(), which runs on every scheduler tick.
     * Only unfinished executions are indexed, so the index stays small on large histories.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_docker_cleanup_executions_unfinished_status_created ON docker_cleanup_executions (status, created_at) WHERE finished_at IS NULL');

            return;
        }

        if (! Schema::hasIndex('docker_cleanup_executions', 'idx_docker_cleanup_executions_unfinished_status_created')) {
            Schema::table('docker_cleanup_executions', function (Blueprint $table) {
                $table->index(['status', 'created_at'], 'idx_docker_cleanup_executions_unfinished_status_created');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_docker_cleanup_executions_unfinished_status_created');

            return;
        }

        if (Schema::hasIndex('docker_cleanup_executions', 'idx_docker_cleanup_executions_unfinished_status_created')) {
            Schema::table('docker_cleanup_executions', function (Blueprint $table) {
                $table->dropIndex('idx_docker_cleanup_executions_unfinished_status_created');
            });
        }
    }
};
