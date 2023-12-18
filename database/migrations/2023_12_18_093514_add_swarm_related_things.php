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
        Schema::table('applications', function (Blueprint $table) {
            $table->integer('swarm_replicas')->default(1);
            $table->text('swarm_placement_constraints')->nullable();
        });
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_swarm_only_worker_nodes')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('swarm_replicas');
            $table->dropColumn('swarm_placement_constraints');
        });
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('is_swarm_only_worker_nodes');
        });
    }
};
