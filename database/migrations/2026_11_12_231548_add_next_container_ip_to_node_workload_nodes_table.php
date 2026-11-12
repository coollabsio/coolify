<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The address of the second container slot of a placement. A rolling deploy starts the new
     * container there while the old one serves (decision 0022).
     */
    public function up(): void
    {
        Schema::table('node_workload_nodes', function (Blueprint $table) {
            $table->string('next_container_ip')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('node_workload_nodes', function (Blueprint $table) {
            $table->dropUnique(['next_container_ip']);
            $table->dropColumn('next_container_ip');
        });
    }
};
