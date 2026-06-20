<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->integer('canvas_x')->nullable()->after('container_subnets');
            $table->integer('canvas_y')->nullable()->after('canvas_x');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->dropColumn(['canvas_x', 'canvas_y']);
        });
    }
};
