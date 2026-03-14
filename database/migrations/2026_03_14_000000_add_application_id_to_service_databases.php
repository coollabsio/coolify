<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add application_id to service_databases to support database services
     * within Application (dockercompose buildpack) deployments. Also makes
     * service_id nullable since application-linked databases don't have a
     * parent Service.
     */
    public function up(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->foreignId('application_id')->nullable()->after('service_id');
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
            // Note: reverting service_id nullability is handled by re-running original migration
        });
    }
};
