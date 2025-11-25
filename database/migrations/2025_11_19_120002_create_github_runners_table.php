<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('github_runners', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('github_runner_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();

            $table->string('runner_id')->nullable()->index();
            $table->string('runner_name');
            $table->string('container_name')->nullable();

            $table->string('job_id')->nullable()->index();
            $table->string('workflow_name')->nullable();
            $table->string('repository_name')->nullable();

            $table->enum('status', ['queued', 'running', 'completed', 'failed'])->default('queued');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['github_runner_source_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('github_runners');
    }
};
