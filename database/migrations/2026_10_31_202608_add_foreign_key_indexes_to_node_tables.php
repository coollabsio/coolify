<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PostgreSQL does not index foreign keys. These columns are read on every container refresh
     * and workload reconcile (`Node::workloads()`, the containers of a workload) and by cascades.
     */
    public function up(): void
    {
        Schema::table('node_workload_nodes', function (Blueprint $table) {
            $table->index('node_id');
        });
        Schema::table('node_containers', function (Blueprint $table) {
            $table->index('node_workload_id');
        });
    }

    public function down(): void
    {
        Schema::table('node_workload_nodes', function (Blueprint $table) {
            $table->dropIndex(['node_id']);
        });
        Schema::table('node_containers', function (Blueprint $table) {
            $table->dropIndex(['node_workload_id']);
        });
    }
};
