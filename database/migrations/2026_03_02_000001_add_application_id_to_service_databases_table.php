<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds application_id to service_databases so that ServiceDatabase records
     * can be owned by an Application (dockercompose buildpack) in addition to
     * a Service. This enables backup support for databases inside GitHub-App-
     * deployed Docker Compose files.
     *
     * @see https://github.com/coollabsio/coolify/issues/7528
     */
    public function up(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            // Make service_id nullable so a ServiceDatabase can belong to
            // either a Service OR an Application (but not both).
            $table->unsignedBigInteger('service_id')->nullable()->change();

            // Add application_id for Application-owned service databases.
            $table->unsignedBigInteger('application_id')->nullable()->after('service_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropColumn('application_id');
            $table->unsignedBigInteger('service_id')->nullable(false)->change();
        });
    }
};
