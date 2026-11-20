<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('application_settings', 'is_auto_cancel_queued_deployments_enabled')) {
                $table->boolean('is_auto_cancel_queued_deployments_enabled')->default(true);
            }
            if (! Schema::hasColumn('application_settings', 'is_auto_cancel_building_deployments_enabled')) {
                $table->boolean('is_auto_cancel_building_deployments_enabled')->default(false);
            }
        });

        if (! Schema::hasColumn('application_deployment_queues', 'deploy_started_at')) {
            Schema::table('application_deployment_queues', function (Blueprint $table) {
                $table->timestamp('deploy_started_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn(['is_auto_cancel_queued_deployments_enabled', 'is_auto_cancel_building_deployments_enabled']);
        });

        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->dropColumn('deploy_started_at');
        });
    }
};
