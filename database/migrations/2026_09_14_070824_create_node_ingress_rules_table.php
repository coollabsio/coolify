<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_ingress_rules', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('node_cluster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_workload_id')->constrained('node_workloads')->cascadeOnDelete();
            $table->string('protocol', 8);
            $table->unsignedInteger('port');
            $table->timestamps();
            $table->unique(['node_cluster_id', 'destination_workload_id', 'protocol', 'port'], 'node_ingress_rule_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_ingress_rules');
    }
};
