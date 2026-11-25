<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Change the default value for the 'image' column
        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->string('image')->default('bitnamilegacy/clickhouse')->change();
        });
        // Optionally, update any existing rows with the old default to the new one
        DB::table('standalone_clickhouses')
            ->where('image', 'bitnami/clickhouse')
            ->update(['image' => 'bitnamilegacy/clickhouse']);
    }

    public function down()
    {
        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->string('image')->default('bitnami/clickhouse')->change();
        });
        // Optionally, revert any changed values
        DB::table('standalone_clickhouses')
            ->where('image', 'bitnamilegacy/clickhouse')
            ->update(['image' => 'bitnami/clickhouse']);
    }
};
