<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->boolean('delete_unused_volumes')->default(false);
            $table->boolean('delete_unused_networks')->default(false);
        });
    }

    public function down()
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn('delete_unused_volumes');
            $table->dropColumn('delete_unused_networks');
        });
    }
};
