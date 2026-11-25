<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsSharedToEnvironmentVariables extends Migration
{
    public function up()
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->boolean('is_shared')->default(false);
        });
    }

    public function down()
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn('is_shared');
        });
    }
}
