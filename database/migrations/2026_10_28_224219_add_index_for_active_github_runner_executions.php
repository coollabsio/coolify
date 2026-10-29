<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_github_runner_executions_status_server_id ON github_runner_executions (status, server_id)');

            return;
        }

        if (! Schema::hasIndex('github_runner_executions', 'idx_github_runner_executions_status_server_id')) {
            Schema::table('github_runner_executions', function (Blueprint $table) {
                $table->index(['status', 'server_id'], 'idx_github_runner_executions_status_server_id');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_github_runner_executions_status_server_id');

            return;
        }

        if (Schema::hasIndex('github_runner_executions', 'idx_github_runner_executions_status_server_id')) {
            Schema::table('github_runner_executions', function (Blueprint $table) {
                $table->dropIndex('idx_github_runner_executions_status_server_id');
            });
        }
    }
};
