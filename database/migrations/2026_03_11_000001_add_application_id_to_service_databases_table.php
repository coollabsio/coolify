<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            // Allow service_databases to be associated with a Git-based Docker Compose
            // application (Application model) in addition to one-click services.
            // service_id must be made nullable to support Application-only databases.
            $table->unsignedBigInteger('service_id')->nullable()->change();
            $table->foreignId('application_id')->nullable()->after('service_id');
        });
    }

    public function down(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropColumn('application_id');
            $table->unsignedBigInteger('service_id')->nullable(false)->change();
        });
    }
};
