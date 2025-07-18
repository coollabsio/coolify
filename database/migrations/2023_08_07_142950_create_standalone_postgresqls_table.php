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
        Schema::create('standalone_postgresqls', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('description')->nullable();

            $table->string('postgres_user')->default('postgres');
            $table->text('postgres_password');
            $table->string('postgres_db')->default('postgres');
            $table->string('postgres_initdb_args')->nullable();
            $table->string('postgres_host_auth_method')->nullable();
            $table->json('init_scripts')->nullable();

            $table->string('status')->default('exited');

            $table->string('image')->default('postgres:15-alpine');
            $table->boolean('is_public')->default(false);
            $table->integer('public_port')->nullable();
            $table->text('ports_mappings')->nullable();

            $table->string('limits_memory')->default('0');
            $table->string('limits_memory_swap')->default('0');
            $table->integer('limits_memory_swappiness')->default(60);
            $table->string('limits_memory_reservation')->default('0');

            $table->string('limits_cpus')->default('0');
            $table->string('limits_cpuset')->nullable()->default('0');
            $table->integer('limits_cpu_shares')->default(1024);

            $table->timestamp('started_at')->nullable();
            $table->morphs('destination');

            $table->foreignId('environment_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('standalone_postgresqls');
    }
};
