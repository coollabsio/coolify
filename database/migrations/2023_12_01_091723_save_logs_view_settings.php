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
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_include_timestamps')->default(false);
        });
        Schema::table('service_applications', function (Blueprint $table) {
            $table->boolean('is_include_timestamps')->default(false);
        });
        Schema::table('service_databases', function (Blueprint $table) {
            $table->boolean('is_include_timestamps')->default(false);
        });
        Schema::table('standalone_mysqls', function (Blueprint $table) {
            $table->boolean('is_include_timestamps')->default(false);
        });
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->boolean('is_include_timestamps')->default(false);
        });
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->boolean('is_include_timestamps')->default(false);
        });
        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->boolean('is_include_timestamps')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('is_include_timestamps');
        });
        Schema::table('service_applications', function (Blueprint $table) {
            $table->dropColumn('is_include_timestamps');
        });
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropColumn('is_include_timestamps');
        });
        Schema::table('standalone_mysqls', function (Blueprint $table) {
            $table->dropColumn('is_include_timestamps');
        });
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropColumn('is_include_timestamps');
        });
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->dropColumn('is_include_timestamps');
        });
        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->dropColumn('is_include_timestamps');
        });
    }
};
