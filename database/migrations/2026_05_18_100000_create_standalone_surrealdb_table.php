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
        Schema::create('standalone_surrealdb', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->string('uuid')->unique();
            $blueprint->string('name');
            $blueprint->string('description')->nullable();
            $blueprint->string('surreal_user')->default('root');
            $blueprint->string('surreal_password')->nullable();
            $blueprint->string('surreal_auth')->default('unauthenticated'); // unauthenticated, namespace, database, root
            $blueprint->string('storage_backend')->default('surrealkv'); // memory, file, surrealkv, tikv, rocksdb
            $blueprint->string('tikv_endpoint')->nullable();
            $blueprint->string('status')->default('exited:unhealthy');
            $blueprint->string('image')->default('surrealdb/surrealdb:latest');
            $blueprint->boolean('is_public')->default(false);
            $blueprint->integer('public_port')->nullable();
            $blueprint->string('ports_mappings')->nullable();
            $blueprint->string('limits_memory')->default('0');
            $blueprint->string('limits_memory_swap')->default('0');
            $blueprint->integer('limits_memory_swappiness')->default(60);
            $blueprint->string('limits_memory_reservation')->default('0');
            $blueprint->string('limits_cpus')->default('0');
            $blueprint->string('limits_cpuset')->nullable();
            $blueprint->integer('limits_cpu_shares')->default(1024);
            $blueprint->dateTime('started_at')->nullable();
            $blueprint->integer('restart_count')->default(0);
            $blueprint->dateTime('last_restart_at')->nullable();
            $blueprint->string('last_restart_type')->nullable();
            $blueprint->dateTime('last_online_at')->nullable();
            $blueprint->integer('public_port_timeout')->default(5000);
            $blueprint->boolean('is_log_drain_enabled')->default(false);
            $blueprint->boolean('is_include_timestamps')->default(false);
            $blueprint->string('custom_docker_run_options')->nullable();
            $blueprint->string('config_hash')->nullable();

            $blueprint->string('destination_type');
            $blueprint->integer('destination_id');
            $blueprint->foreignId('environment_id')->constrained()->onDelete('cascade');
            $blueprint->softDeletes();
            $blueprint->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('standalone_surrealdb');
    }
};
