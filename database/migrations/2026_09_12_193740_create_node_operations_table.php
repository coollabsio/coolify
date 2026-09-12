<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_operations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_workload_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('node_workload_revision_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('command_type', 100);
            $table->string('idempotency_key', 255);
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->json('request');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['node_id', 'idempotency_key']);
            $table->index(['node_id', 'created_at']);
            $table->index(['status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_operations');
    }
};
