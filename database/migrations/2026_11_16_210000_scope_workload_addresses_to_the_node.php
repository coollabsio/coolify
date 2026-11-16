<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each cluster gives its Nodes the same workload networks (198.18.<n>.0/24), so a workload
     * address is unique only on its Node. A unique address over all Nodes blocked the first
     * deployment on a second cluster.
     */
    public function up(): void
    {
        Schema::table('node_workload_nodes', function (Blueprint $table) {
            $table->dropUnique(['container_ip']);
            $table->dropUnique(['next_container_ip']);
            $table->unique(['node_id', 'container_ip']);
            $table->unique(['node_id', 'next_container_ip']);
        });
    }

    public function down(): void
    {
        Schema::table('node_workload_nodes', function (Blueprint $table) {
            $table->dropUnique(['node_id', 'container_ip']);
            $table->dropUnique(['node_id', 'next_container_ip']);
            $table->unique('container_ip');
            $table->unique('next_container_ip');
        });
    }
};
