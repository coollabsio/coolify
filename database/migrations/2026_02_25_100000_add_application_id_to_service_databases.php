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
        Schema::table('service_databases', function (Blueprint $table) {
            // Make service_id nullable to support Application-owned compose databases
            $table->unsignedBigInteger('service_id')->nullable()->change();

            // Add application_id for Docker Compose (GitHub App buildpack) deployments
            $table->unsignedBigInteger('application_id')->nullable()->after('service_id');
            $table->foreign('application_id')->references('id')->on('applications')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropColumn('application_id');

            // Restore service_id to non-nullable
            $table->unsignedBigInteger('service_id')->nullable(false)->change();
        });
    }
};
