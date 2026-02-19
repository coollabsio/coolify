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
        Schema::table('service_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('service_applications', 'application_id')) {
                $table->foreignId('application_id')->nullable()->after('service_id');
            }
            $table->unsignedBigInteger('service_id')->nullable()->change();
        });

        Schema::table('service_databases', function (Blueprint $table) {
            if (! Schema::hasColumn('service_databases', 'application_id')) {
                $table->foreignId('application_id')->nullable()->after('service_id');
            }
            $table->unsignedBigInteger('service_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_applications', function (Blueprint $table) {
            if (Schema::hasColumn('service_applications', 'application_id')) {
                $table->dropColumn('application_id');
            }
            $table->unsignedBigInteger('service_id')->nullable(false)->change();
        });

        Schema::table('service_databases', function (Blueprint $table) {
            if (Schema::hasColumn('service_databases', 'application_id')) {
                $table->dropColumn('application_id');
            }
            $table->unsignedBigInteger('service_id')->nullable(false)->change();
        });
    }
};
