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
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_applications_environment_id_all ON applications (environment_id)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_docker_cleanup_executions_server_created_at ON docker_cleanup_executions (server_id, created_at DESC)');

            return;
        }

        if (! Schema::hasIndex('applications', 'idx_applications_environment_id_all')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->index('environment_id', 'idx_applications_environment_id_all');
            });
        }

        if (! Schema::hasIndex('docker_cleanup_executions', 'idx_docker_cleanup_executions_server_created_at')) {
            Schema::table('docker_cleanup_executions', function (Blueprint $table) {
                $table->index(['server_id', 'created_at'], 'idx_docker_cleanup_executions_server_created_at');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_docker_cleanup_executions_server_created_at');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_applications_environment_id_all');

            return;
        }

        if (Schema::hasIndex('docker_cleanup_executions', 'idx_docker_cleanup_executions_server_created_at')) {
            Schema::table('docker_cleanup_executions', function (Blueprint $table) {
                $table->dropIndex('idx_docker_cleanup_executions_server_created_at');
            });
        }

        if (Schema::hasIndex('applications', 'idx_applications_environment_id_all')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->dropIndex('idx_applications_environment_id_all');
            });
        }
    }
};
