<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standalone_influxdbs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('fqdn')->nullable();

            $table->string('influxdb_admin_user')->default('influx');
            $table->text('influxdb_admin_password');
            $table->text('influxdb_admin_token');
            $table->string('influxdb_org')->default('coolify');
            $table->string('influxdb_bucket')->default('coolify');

            $table->boolean('is_log_drain_enabled')->default(false);
            $table->boolean('is_include_timestamps')->default(false);
            $table->softDeletes();

            $table->string('status')->default('exited');
            $table->string('image')->default('influxdb:2.7-alpine');

            $table->boolean('is_public')->default(false);
            $table->integer('public_port')->nullable();
            $table->integer('public_port_timeout')->nullable()->default(3600);
            $table->text('ports_mappings')->nullable();

            $table->string('limits_memory')->default('0');
            $table->string('limits_memory_swap')->default('0');
            $table->integer('limits_memory_swappiness')->default(60);
            $table->string('limits_memory_reservation')->default('0');

            $table->string('limits_cpus')->default('0');
            $table->string('limits_cpuset')->nullable()->default(null);
            $table->integer('limits_cpu_shares')->default(1024);

            $table->string('config_hash')->nullable();
            $table->text('custom_docker_run_options')->nullable();

            $table->boolean('health_check_enabled')->default(true);
            $table->integer('health_check_interval')->default(15);
            $table->integer('health_check_timeout')->default(5);
            $table->integer('health_check_retries')->default(5);
            $table->integer('health_check_start_period')->default(5);

            $table->integer('restart_count')->default(0);
            $table->timestamp('last_restart_at')->nullable();
            $table->string('last_restart_type')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_online_at')->default(now());
            $table->morphs('destination');
            $table->foreignId('environment_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standalone_influxdbs');
    }
};
