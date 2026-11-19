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
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_deployment_queues_app_created_id ON application_deployment_queues (application_id, created_at DESC, id DESC)');

            return;
        }

        if (! Schema::hasIndex('application_deployment_queues', 'idx_deployment_queues_app_created_id')) {
            Schema::table('application_deployment_queues', function (Blueprint $table) {
                $table->index(['application_id', 'created_at', 'id'], 'idx_deployment_queues_app_created_id');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_deployment_queues_app_created_id');

            return;
        }

        if (Schema::hasIndex('application_deployment_queues', 'idx_deployment_queues_app_created_id')) {
            Schema::table('application_deployment_queues', function (Blueprint $table) {
                $table->dropIndex('idx_deployment_queues_app_created_id');
            });
        }
    }
};
