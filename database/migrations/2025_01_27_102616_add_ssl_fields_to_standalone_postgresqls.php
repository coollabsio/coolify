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
            $table->boolean('enable_ssl')->default(true);
            $table->string('ssl_mode')->nullable()->default('verify-full');
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
    }
};
