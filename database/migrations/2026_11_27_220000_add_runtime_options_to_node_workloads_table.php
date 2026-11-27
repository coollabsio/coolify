<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The container runtime options of a cluster application (decision 0032): the v4 Custom Docker
 * Options, container labels, GPU, and the pre- and post-deployment commands. A Node gets the
 * opt-in of a team admin for GPU workloads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->text('custom_docker_run_options')->nullable();
            $table->text('custom_labels')->nullable();
            $table->boolean('is_gpu_enabled')->default(false);
            // A number or "all". Device IDs replace the count when they are set.
            $table->string('gpu_count')->nullable();
            $table->string('gpu_device_ids')->nullable();
            $table->text('pre_deployment_command')->nullable();
            $table->text('post_deployment_command')->nullable();
            $table->unsignedInteger('deployment_command_timeout')->default(300);
        });
        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('is_gpu_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropColumn(['custom_docker_run_options', 'custom_labels', 'is_gpu_enabled', 'gpu_count', 'gpu_device_ids', 'pre_deployment_command', 'post_deployment_command', 'deployment_command_timeout']);
        });
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('is_gpu_enabled');
        });
    }
};
