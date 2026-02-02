<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration adds application_id to service_databases table to support
     * database detection for Docker Compose deployments via GitHub App.
     *
     * Previously, ServiceDatabase only supported Service model (one-click services,
     * Empty Docker Compose). Now it also supports Application model (GitHub App
     * with dockercompose buildpack).
     */
    public function up(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            // Add nullable application_id for GitHub App deployments
            $table->foreignId('application_id')->nullable()->after('service_id');

            // Add index for efficient queries
            $table->index('application_id');
        });

        // Make service_id nullable since we now support either service_id OR application_id
        Schema::table('service_databases', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropIndex(['application_id']);
            $table->dropColumn('application_id');
        });

        // Restore service_id to non-nullable (this may fail if there are rows with null service_id)
        Schema::table('service_databases', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable(false)->change();
        });
    }
};
