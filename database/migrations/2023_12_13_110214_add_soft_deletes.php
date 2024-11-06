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
        Schema::table('applications', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('standalone_mysqls', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('standalone_mariadbs', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('service_applications', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('service_databases', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('standalone_mysqls', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('standalone_mariadbs', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('service_applications', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
