<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Concurrent builds on builder Nodes (decision 0035): the build slots and the BuildKit limits of
 * a Node, and the cluster build queue. A build without a Node waits in the FIFO queue of its
 * cluster (`builder_queued_at`) and does not hold a Horizon worker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->unsignedTinyInteger('concurrent_builds')->default(1);
            // Null: Sentinel uses 50% of the memory of the Node, no CPU limit, and 20% of the root disk.
            $table->unsignedBigInteger('build_memory_limit_bytes')->nullable();
            $table->unsignedInteger('build_cpu_limit_millis')->nullable();
            $table->unsignedBigInteger('build_cache_bytes')->nullable();
        });
        Schema::table('node_builds', function (Blueprint $table) {
            $table->foreignId('node_cluster_id')->nullable()->constrained('node_clusters')->nullOnDelete();
            $table->timestamp('builder_queued_at')->nullable();
            $table->index(['node_cluster_id', 'status', 'node_id']);
        });
    }

    public function down(): void
    {
        Schema::table('node_builds', function (Blueprint $table) {
            $table->dropIndex(['node_cluster_id', 'status', 'node_id']);
            $table->dropConstrainedForeignId('node_cluster_id');
            $table->dropColumn('builder_queued_at');
        });
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['concurrent_builds', 'build_memory_limit_bytes', 'build_cpu_limit_millis', 'build_cache_bytes']);
        });
    }
};
