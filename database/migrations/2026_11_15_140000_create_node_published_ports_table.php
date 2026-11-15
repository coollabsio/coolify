<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A TCP or UDP port of a cluster workload that every Node of the cluster publishes (decision 0016).
 * One protocol and host port leads to one workload in a cluster.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_published_ports', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('node_cluster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_workload_id')->constrained()->cascadeOnDelete();
            $table->string('protocol', 8);
            $table->unsignedInteger('host_port');
            $table->unsignedInteger('container_port');
            $table->json('source_cidrs')->nullable();
            $table->timestamps();
            $table->unique(['node_cluster_id', 'protocol', 'host_port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_published_ports');
    }
};
