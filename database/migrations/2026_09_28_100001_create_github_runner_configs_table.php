<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One GitHub Actions runner configuration per build server.
     */
    public function up(): void
    {
        Schema::create('github_runner_configs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('server_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('github_app_id')->constrained()->cascadeOnDelete();
            $table->json('labels');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_dedicated')->default(false);
            $table->unsignedSmallInteger('max_runners')->default(2);
            $table->string('docker_mode')->default('none');
            $table->string('runner_image')->nullable();
            $table->string('cpu_limit')->nullable();
            $table->string('memory_limit')->nullable();
            $table->unsignedInteger('capacity_wait_timeout')->default(60);
            $table->unsignedInteger('idle_timeout')->default(10);
            $table->unsignedInteger('job_timeout')->default(360);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_runner_configs');
    }
};
