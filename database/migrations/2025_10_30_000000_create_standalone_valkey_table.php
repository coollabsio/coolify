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
        Schema::create('standalone_valkeys', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('description')->nullable();

            $table->text('valkey_password');
            $table->longText('valkey_conf')->nullable();

            $table->string('status')->default('exited');

            $table->string('image')->default('valkey/valkey:8.0');

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

            // SSL/TLS configuration
            $table->boolean('enable_ssl')->default(false);
            $table->enum('ssl_mode', ['allow', 'prefer', 'require', 'verify-ca', 'verify-full'])->default('require');

            // Configuration
            $table->text('custom_docker_run_options')->nullable();
            $table->string('config_hash')->nullable();
            $table->boolean('is_log_drain_enabled')->default(false);

            // Status tracking
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_online_at')->nullable();

            // Multi-tenancy and relationships
            $table->morphs('destination');
            $table->foreignId('environment_id')->nullable();

            // Soft deletes
            $table->softDeletes();

            // Timestamps
            $table->timestamps();
        });

        // Add foreign key to environment_variables table
        Schema::table('environment_variables', function (Blueprint $table) {
            if (! Schema::hasColumn('environment_variables', 'standalone_valkey_id')) {
                $table->foreignId('standalone_valkey_id')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            if (Schema::hasColumn('environment_variables', 'standalone_valkey_id')) {
                $table->dropColumn('standalone_valkey_id');
            }
        });

        Schema::dropIfExists('standalone_valkeys');
    }
};
