<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standalone_surrealdbs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('description')->nullable();

            $table->string('surrealdb_user')->default('root');
            $table->text('surrealdb_password');
            $table->string('surrealdb_database')->default('default');
            $table->string('surrealdb_namespace')->default('default');

            $table->boolean('is_log_drain_enabled')->default(false);
            $table->boolean('is_include_timestamps')->default(false);
            $table->softDeletes();

            $table->string('status')->default('exited');

            $table->string('image')->default('surrealdb/surrealdb:latest');

            $table->boolean('is_public')->default(false);
            $table->integer('public_port')->nullable();
            $table->text('ports_mappings')->nullable();

            $table->string('limits_memory')->default('0');
            $table->string('limits_memory_swap')->default('0');
            $table->integer('limits_memory_swappiness')->default(60);
            $table->string('limits_memory_reservation')->default('0');

            $table->string('limits_cpus')->default('0');
            $table->string('limits_cpuset')->nullable()->default(null);
            $table->integer('limits_cpu_shares')->default(1024);

            $table->text('custom_docker_run_options')->nullable();
            $table->string('config_hash')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_online_at')->nullable();
            $table->morphs('destination');
            $table->foreignId('environment_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standalone_surrealdbs');
    }
};
