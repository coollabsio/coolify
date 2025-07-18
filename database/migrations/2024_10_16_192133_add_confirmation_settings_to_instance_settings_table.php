<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('disable_two_step_confirmation')->default(false);
        });
    }

    public function down()
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('disable_two_step_confirmation');
        });
    }
};
