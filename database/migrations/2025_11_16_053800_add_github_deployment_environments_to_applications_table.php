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
        Schema::table('applications', function (Blueprint $table) {
            $table->string('deployment_production_environment')->nullable();
            $table->string('deployment_preview_environment')->nullable();
        });
        Schema::table('github_apps', function (Blueprint $table) {
            $table->string('deployments')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('deployment_production_environment');
            $table->dropColumn('deployment_preview_environment');
        });
        Schema::table('github_apps', function (Blueprint $table) {
            $table->dropColumn('deployments');
        });
    }
};
