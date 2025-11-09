<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Change the default value for the 'image' column to the official ClickHouse LTS image
        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->string('image')->default('clickhouse/clickhouse-server:lts')->change();
        });

        // Migrate known legacy images to the official image to keep instances current
        DB::table('standalone_clickhouses')
            ->whereIn('image', ['bitnami/clickhouse', 'bitnamilegacy/clickhouse'])
            ->update(['image' => 'clickhouse/clickhouse-server:lts']);
    }

    public function down()
    {
        // Revert the default back to the previous legacy image if this migration is rolled back
        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->string('image')->default('bitnamilegacy/clickhouse')->change();
        });

        // Revert only rows that were updated to the official image by this migration
        DB::table('standalone_clickhouses')
            ->where('image', 'clickhouse/clickhouse-server:lts')
            ->update(['image' => 'bitnamilegacy/clickhouse']);
    }
};
