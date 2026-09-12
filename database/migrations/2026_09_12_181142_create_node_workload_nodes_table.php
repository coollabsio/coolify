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
            $table->timestamps();
            $table->primary(['node_workload_id', 'node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_workload_nodes');
    }
};
