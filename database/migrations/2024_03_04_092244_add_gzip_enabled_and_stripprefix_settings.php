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
            $table->boolean('is_gzip_enabled')->default(true);
            $table->boolean('is_stripprefix_enabled')->default(true);
        });
        Schema::table('service_applications', function (Blueprint $table) {
            $table->boolean('is_stripprefix_enabled')->default(true);
        });
        Schema::table('service_databases', function (Blueprint $table) {
            $table->boolean('is_gzip_enabled')->default(true);
            $table->boolean('is_stripprefix_enabled')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('is_gzip_enabled');
            $table->dropColumn('is_stripprefix_enabled');
        });
        Schema::table('service_applications', function (Blueprint $table) {
            $table->dropColumn('is_stripprefix_enabled');
        });
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropColumn('is_gzip_enabled');
            $table->dropColumn('is_stripprefix_enabled');
        });
    }
};
