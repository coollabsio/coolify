<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each cluster is its own WireGuard mesh. Workload subnets are routed only inside one cluster, and cluster
     * ranges of different teams never meet, so both are unique within their owner and not across the instance.
     */
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropUnique(['workload_cidr']);
            $table->unique(['node_cluster_id', 'workload_cidr']);
        });
        Schema::table('node_clusters', function (Blueprint $table) {
            $table->dropUnique(['cidr']);
            $table->unique(['team_id', 'cidr']);
        });
    }

    public function down(): void
    {
        Schema::table('node_clusters', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'cidr']);
            $table->unique('cidr');
        });
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropUnique(['node_cluster_id', 'workload_cidr']);
            $table->unique('workload_cidr');
        });
    }
};
