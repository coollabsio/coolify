<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per ephemeral runner. The unique trigger job id stops duplicate webhook
     * deliveries from starting a second runner.
     */
    public function up(): void
    {
        Schema::create('github_runner_executions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('github_app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('github_runner_config_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('queued');
            $table->unsignedBigInteger('trigger_workflow_job_id');
            $table->unsignedBigInteger('workflow_job_id')->nullable();
            $table->string('workflow_job_html_url')->nullable();
            $table->string('workflow_name')->nullable();
            $table->string('job_name')->nullable();
            $table->string('repository_full_name')->nullable();
            $table->json('labels')->nullable();
            $table->string('runner_name')->nullable();
            $table->unsignedBigInteger('runner_id')->nullable();
            $table->string('conclusion')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('provision_attempts')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['github_app_id', 'trigger_workflow_job_id']);
            $table->index(['github_app_id', 'runner_name']);
            $table->index(['github_app_id', 'status']);
            $table->index(['github_runner_config_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_runner_executions');
    }
};
