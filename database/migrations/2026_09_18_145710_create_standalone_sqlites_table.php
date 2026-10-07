<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standalone_sqlites', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('description')->nullable();

            $table->string('sqlite_databases')->default('database.sqlite');

            $table->boolean('is_log_drain_enabled')->default(false);
            $table->boolean('is_include_timestamps')->default(false);
            $table->softDeletes();

            $table->string('status')->default('exited');
            $table->integer('restart_count')->default(0);
            $table->timestamp('last_restart_at')->nullable();
            $table->string('last_restart_type', 10)->nullable();

            $table->string('image')->default('peakimages/sqlite:3.53.4-v0.1.0');

            $table->boolean('is_public')->default(false);

            $table->string('limits_memory')->default('0');
            $table->string('limits_memory_swap')->default('0');
            $table->integer('limits_memory_swappiness')->default(60);
            $table->string('limits_memory_reservation')->default('0');

            $table->string('limits_cpus')->default('0');
            $table->string('limits_cpuset')->nullable()->default(null);
            $table->integer('limits_cpu_shares')->default(1024);

            $table->timestamp('started_at')->nullable();
            $table->morphs('destination');
            $table->foreignId('environment_id')->nullable();

            $table->string('config_hash')->nullable();
            $table->text('custom_docker_run_options')->nullable();

            $table->boolean('health_check_enabled')->default(true);
            $table->integer('health_check_interval')->default(15);
            $table->integer('health_check_timeout')->default(5);
            $table->integer('health_check_retries')->default(5);
            $table->integer('health_check_start_period')->default(5);

            $table->timestamps();
            $table->timestamp('last_online_at')->default(now());
        });
    }
};
