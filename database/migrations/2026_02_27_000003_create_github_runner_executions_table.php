<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_runner_executions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('github_runner_config_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued');
            $table->string('runner_name')->nullable();
            $table->string('runner_dir')->nullable();
            $table->unsignedBigInteger('workflow_job_id');
            $table->string('workflow_name')->nullable();
            $table->string('repository_full_name')->nullable();
            $table->unsignedBigInteger('runner_id')->nullable();
            $table->integer('pid')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'status']);
            $table->index('workflow_job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_runner_executions');
    }
};
