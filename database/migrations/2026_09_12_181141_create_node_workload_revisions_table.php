<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_workload_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('node_workload_id')->constrained()->cascadeOnDelete();
            $table->string('configuration_hash', 64);
            $table->string('image')->nullable();
            $table->json('configuration');
            $table->timestamps();
            $table->unique(['node_workload_id', 'configuration_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_workload_revisions');
    }
};
