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
        Schema::table('server_settings', function (Blueprint $table) {
            $table->renameColumn('is_part_of_swarm', 'is_swarm_manager');
            $table->boolean('is_swarm_worker')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->renameColumn('is_swarm_manager', 'is_part_of_swarm');
            $table->dropColumn('is_swarm_worker');
        });
    }
};
