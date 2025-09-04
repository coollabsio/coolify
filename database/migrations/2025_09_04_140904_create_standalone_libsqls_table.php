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
        Schema::create('standalone_libsqls', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('description')->nullable();

            // LibSQL specific fields
            $table->text('libsql_password');
            $table->string('libsql_db')->nullable();

            $table->string('status')->default('exited');

            $table->string('image')->default('ghcr.io/tursodatabase/libsql-server:latest');
            $table->text('custom_docker_run_options')->nullable();
            $table->boolean('is_public')->default(false);
            $table->integer('public_port')->nullable();
            $table->text('ports_mappings')->nullable();
            $table->boolean('enable_ssl')->default(true);

            // Resource limits
            $table->string('limits_memory')->default('0');
            $table->string('limits_memory_swap')->default('0');
            $table->integer('limits_memory_swappiness')->default(60);
            $table->string('limits_memory_reservation')->default('0');

            $table->string('limits_cpus')->default('0');
            $table->string('limits_cpuset')->nullable()->default(null);
            $table->integer('limits_cpu_shares')->default(1024);

            // Additional LibSQL specific fields
            $table->boolean('enable_bottomless_replication')->default(false);
            $table->string('s3_bucket')->nullable();
            $table->string('s3_region')->nullable();
            $table->text('s3_access_key')->nullable();
            $table->text('s3_secret_key')->nullable();
            $table->string('s3_endpoint')->nullable();
            $table->string('sqld_node')->nullable();
            $table->integer('sqld_http_port')->nullable();
            $table->integer('sqld_grpc_port')->nullable();

            // Configuration and monitoring
            $table->string('config_hash')->nullable();
            $table->boolean('is_log_drain_enabled')->default(false);
            $table->timestamp('last_online_at')->default(now())->after('updated_at');


            // Resource limits

            $table->timestamp('started_at')->nullable();
            $table->morphs('destination');

            $table->foreignId('environment_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->foreignId('standalone_libsql_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('standalone_libsqls');
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn('standalone_libsql_id');
        });
    }
};
