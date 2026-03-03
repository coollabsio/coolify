<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_runner_configs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('server_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('github_app_id')->constrained()->cascadeOnDelete();
            $table->json('labels')->default('["self-hosted","coolify"]');
            $table->boolean('is_enabled')->default(true);
            $table->integer('max_runners')->default(4);
            $table->string('runner_user')->default('runner');
            $table->string('runner_version')->nullable();
            $table->string('runner_arch')->default('x64');
            $table->string('runner_base_dir')->default('/opt/github-runners');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_runner_configs');
    }
};
