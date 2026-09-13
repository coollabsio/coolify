<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_workload_nodes', function (Blueprint $table) {
            $table->foreignId('node_workload_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->string('container_ip')->nullable()->unique();
            $table->timestamps();
            $table->primary(['node_workload_id', 'node_id']);
        });

        Schema::create('node_firewall_rules', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('node_cluster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_workload_id')->constrained('node_workloads')->cascadeOnDelete();
            $table->foreignId('destination_workload_id')->constrained('node_workloads')->cascadeOnDelete();
            $table->string('protocol', 8);
            $table->unsignedInteger('port');
            $table->timestamps();
            $table->unique(['node_cluster_id', 'source_workload_id', 'destination_workload_id', 'protocol', 'port'], 'node_firewall_rule_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_firewall_rules');
        Schema::dropIfExists('node_workload_nodes');
    }
};
