<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_containers', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_workload_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('node_workload_revision_id')->nullable()->constrained()->nullOnDelete();
            $table->string('runtime_id');
            $table->string('name');
            $table->string('image');
            $table->string('state')->index();
            $table->string('health_status')->nullable();
            $table->unsignedInteger('restart_count')->nullable();
            $table->json('ports')->nullable();
            $table->json('labels');
            $table->string('management_state')->index();
            $table->boolean('is_managed')->default(false)->index();
            $table->timestamp('runtime_created_at')->nullable();
            $table->timestamp('runtime_started_at')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->unique(['node_id', 'runtime_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_containers');
    }
};
