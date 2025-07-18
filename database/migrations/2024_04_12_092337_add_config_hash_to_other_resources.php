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
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->string('config_hash')->nullable();
        });
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->string('config_hash')->nullable();
        });
        Schema::table('standalone_mysqls', function (Blueprint $table) {
            $table->string('config_hash')->nullable();
        });
        Schema::table('standalone_mariadbs', function (Blueprint $table) {
            $table->string('config_hash')->nullable();
        });
        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->string('config_hash')->nullable();
        });
        Schema::table('standalone_keydbs', function (Blueprint $table) {
            $table->string('config_hash')->nullable();
        });
        Schema::table('standalone_dragonflies', function (Blueprint $table) {
            $table->string('config_hash')->nullable();
        });
        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->string('config_hash')->nullable();
        });
        Schema::table('services', function (Blueprint $table) {
            $table->string('config_hash')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropColumn('config_hash');
        });
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->dropColumn('config_hash');
        });
        Schema::table('standalone_mysqls', function (Blueprint $table) {
            $table->dropColumn('config_hash');
        });
        Schema::table('standalone_mariadbs', function (Blueprint $table) {
            $table->dropColumn('config_hash');
        });
        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->dropColumn('config_hash');
        });
        Schema::table('standalone_keydbs', function (Blueprint $table) {
            $table->dropColumn('config_hash');
        });
        Schema::table('standalone_dragonflies', function (Blueprint $table) {
            $table->dropColumn('config_hash');
        });
        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->dropColumn('config_hash');
        });
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('config_hash');
        });
    }
};
