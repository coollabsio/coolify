<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add application_id to service_databases for Docker Compose via GitHub App deployments.
     *
     * When a Docker Compose file is deployed via the GitHub App (using the `dockercompose`
     * buildpack), database services are detected but ServiceDatabase records are not created
     * because only Application models are used (not Service models). This migration adds
     * an optional application_id FK so ServiceDatabase can belong to either a Service or
     * an Application.
     */
    public function up(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->foreignId('application_id')->nullable()->after('service_id')->constrained()->cascadeOnDelete();

            // Make service_id nullable since a ServiceDatabase can now belong to an Application
            $table->foreignId('service_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropColumn('application_id');

            // Restore service_id as required
            $table->foreignId('service_id')->nullable(false)->change();
        });
    }
};
