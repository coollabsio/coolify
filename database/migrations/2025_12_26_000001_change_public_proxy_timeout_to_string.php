<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Convert integer timeout values to string for nginx time format support
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->string('public_proxy_timeout')->default('0')->change();
        });

        Schema::table('standalone_mysqls', function (Blueprint $table) {
            $table->string('public_proxy_timeout')->default('0')->change();
        });

        Schema::table('standalone_mariadbs', function (Blueprint $table) {
            $table->string('public_proxy_timeout')->default('0')->change();
        });

        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->string('public_proxy_timeout')->default('0')->change();
        });

        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->string('public_proxy_timeout')->default('0')->change();
        });

        Schema::table('standalone_keydbs', function (Blueprint $table) {
            $table->string('public_proxy_timeout')->default('0')->change();
        });

        Schema::table('standalone_dragonflies', function (Blueprint $table) {
            $table->string('public_proxy_timeout')->default('0')->change();
        });

        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->string('public_proxy_timeout')->default('0')->change();
        });

        Schema::table('service_databases', function (Blueprint $table) {
            $table->string('public_proxy_timeout')->default('0')->change();
        });
    }

    public function down(): void
    {
        // Revert back to integer
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->integer('public_proxy_timeout')->default(0)->change();
        });

        Schema::table('standalone_mysqls', function (Blueprint $table) {
            $table->integer('public_proxy_timeout')->default(0)->change();
        });

        Schema::table('standalone_mariadbs', function (Blueprint $table) {
            $table->integer('public_proxy_timeout')->default(0)->change();
        });

        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->integer('public_proxy_timeout')->default(0)->change();
        });

        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->integer('public_proxy_timeout')->default(0)->change();
        });

        Schema::table('standalone_keydbs', function (Blueprint $table) {
            $table->integer('public_proxy_timeout')->default(0)->change();
        });

        Schema::table('standalone_dragonflies', function (Blueprint $table) {
            $table->integer('public_proxy_timeout')->default(0)->change();
        });

        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->integer('public_proxy_timeout')->default(0)->change();
        });

        Schema::table('service_databases', function (Blueprint $table) {
            $table->integer('public_proxy_timeout')->default(0)->change();
        });
    }
};
