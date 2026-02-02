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
     * database detection and backup for Docker Compose deployments via GitHub App.
     * 
     * @see https://github.com/coollabsio/coolify/issues/7528
     */
    public function up(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            // Make service_id nullable since we can now have either service_id or application_id
            $table->foreignId('application_id')->nullable()->after('service_id');
            
            // Add index for efficient queries
            $table->index('application_id');
        });

        // Modify service_id to be nullable (existing records will still have it)
        Schema::table('service_databases', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->nullable()->change();
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

        // Restore service_id to non-nullable
        Schema::table('service_databases', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->nullable(false)->change();
        });
    }
};
