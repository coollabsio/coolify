<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Enable database detection and backup support for Docker Compose deployments via GitHub App.
     * This adds application_id to allow ServiceDatabase records to be associated with Applications
     * (not just Services), enabling backup functionality for databases in GitHub App deployments.
     *
     * @see https://github.com/coollabsio/coolify/issues/7528
     */
    public function up(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            // Add application_id for GitHub App docker-compose deployments
            $table->foreignId('application_id')->nullable()->after('service_id');

            // Make service_id nullable since we can now have either service_id OR application_id
            $table->foreignId('service_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropColumn('application_id');
            $table->foreignId('service_id')->nullable(false)->change();
        });
    }
};
