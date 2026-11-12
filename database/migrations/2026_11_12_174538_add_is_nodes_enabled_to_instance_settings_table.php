<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The column default keeps the SENTINEL_HOST_ENABLED value of the environment at migration time,
     * also for the settings row that a fresh install seeds after the migrations.
     */
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('is_nodes_enabled')->default((bool) config('constants.sentinel.host_enabled', false));
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('is_nodes_enabled');
        });
    }
};
