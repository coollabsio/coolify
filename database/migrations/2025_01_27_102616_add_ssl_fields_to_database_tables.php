<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->boolean('enable_ssl')->default(false);
            $table->enum('ssl_mode', ['allow', 'prefer', 'require', 'verify-ca', 'verify-full'])->default('require');
        });
        Schema::table('standalone_mysqls', function (Blueprint $table) {
            $table->boolean('enable_ssl')->default(false);
            $table->enum('ssl_mode', ['PREFERRED', 'REQUIRED', 'VERIFY_CA', 'VERIFY_IDENTITY'])->default('REQUIRED');
        });
        Schema::table('standalone_mariadbs', function (Blueprint $table) {
            $table->boolean('enable_ssl')->default(false);
        });
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->boolean('enable_ssl')->default(false);
        });
        Schema::table('standalone_keydbs', function (Blueprint $table) {
            $table->boolean('enable_ssl')->default(false);
        });
        Schema::table('standalone_dragonflies', function (Blueprint $table) {
            $table->boolean('enable_ssl')->default(false);
        });
        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->boolean('enable_ssl')->default(true);
            $table->enum('ssl_mode', ['allow', 'prefer', 'require', 'verify-full'])->default('require');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropColumn('enable_ssl');
            $table->dropColumn('ssl_mode');
        });
        Schema::table('standalone_mysqls', function (Blueprint $table) {
            $table->dropColumn('enable_ssl');
            $table->dropColumn('ssl_mode');
        });
        Schema::table('standalone_mariadbs', function (Blueprint $table) {
            $table->dropColumn('enable_ssl');
        });
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->dropColumn('enable_ssl');
        });
        Schema::table('standalone_keydbs', function (Blueprint $table) {
            $table->dropColumn('enable_ssl');
        });
        Schema::table('standalone_dragonflies', function (Blueprint $table) {
            $table->dropColumn('enable_ssl');
        });
        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->dropColumn('enable_ssl');
            $table->dropColumn('ssl_mode');
        });
    }
};
