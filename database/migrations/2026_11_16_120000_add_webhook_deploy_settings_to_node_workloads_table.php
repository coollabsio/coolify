<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The v4 deployment settings of a Git application, with the v4 defaults: a push deploys, a newer
     * push cancels the older waiting webhook builds, and a running webhook build finishes. A build
     * records whether a webhook started it, and waits while another build of its workload runs.
     */
    public function up(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->boolean('is_auto_deploy_enabled')->default(true);
            $table->boolean('is_auto_cancel_queued_deployments_enabled')->default(true);
            $table->boolean('is_auto_cancel_building_deployments_enabled')->default(false);
        });
        Schema::table('node_builds', function (Blueprint $table) {
            $table->boolean('is_webhook')->default(false);
            $table->boolean('is_waiting')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropColumn(['is_auto_deploy_enabled', 'is_auto_cancel_queued_deployments_enabled', 'is_auto_cancel_building_deployments_enabled']);
        });
        Schema::table('node_builds', function (Blueprint $table) {
            $table->dropColumn(['is_webhook', 'is_waiting']);
        });
    }
};
