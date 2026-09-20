<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_clusters', function (Blueprint $table) {
            $table->unsignedTinyInteger('cpu_pressure_threshold')->default(95);
            $table->unsignedTinyInteger('memory_pressure_threshold')->default(90);
            $table->unsignedTinyInteger('disk_pressure_threshold')->default(90);
            $table->unsignedSmallInteger('resource_stale_after_minutes')->default(5);
        });
    }

    public function down(): void
    {
        Schema::table('node_clusters', function (Blueprint $table) {
            $table->dropColumn([
                'cpu_pressure_threshold',
                'memory_pressure_threshold',
                'disk_pressure_threshold',
                'resource_stale_after_minutes',
            ]);
        });
    }
};
