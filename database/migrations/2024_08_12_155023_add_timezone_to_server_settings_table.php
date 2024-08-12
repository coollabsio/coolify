<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTimezoneToServerSettingsTable extends Migration
{
    public function up()
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->string('server_timezone')->default('UTC');
        });
    }

    public function down()
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn('server_timezone');
        });
    }
}