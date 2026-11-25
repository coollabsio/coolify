<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpdateServerSettingsDefaultTimezone extends Migration
{
    public function up()
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->string('server_timezone')->default('UTC')->change();
        });

        DB::table('server_settings')
            ->whereNull('server_timezone')
            ->orWhere('server_timezone', '')
            ->update(['server_timezone' => 'UTC']);
    }

    public function down()
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->string('server_timezone')->default('')->change();
        });
    }
}
