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
            $table->id()->primary();
            $table->boolean('is_static')->default(false);
            $table->boolean('is_git_submodules_allowed')->default(true);
            $table->boolean('is_git_lfs_allowed')->default(true);
            $table->boolean('is_auto_deploy')->default(true);
            $table->boolean('is_force_https')->default(true);
            // $table->boolean('is_dual_cert')->default(false);
            $table->boolean('is_debug')->default(false);
            $table->boolean('is_previews')->default(false);
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
