<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('application_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_static')->default(false);
            $table->boolean('is_git_submodules_enabled')->default(true);
            $table->boolean('is_git_lfs_enabled')->default(true);
            $table->boolean('is_auto_deploy_enabled')->default(true);
            $table->boolean('is_force_https_enabled')->default(true);
            $table->boolean('is_debug_enabled')->default(false);
            $table->boolean('is_preview_deployments_enabled')->default(false);
            // $table->boolean('is_dual_cert')->default(false);
            // $table->boolean('is_custom_ssl')->default(false);
            // $table->boolean('is_http2')->default(false);
            $table->foreignId('application_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_settings');
    }
};
