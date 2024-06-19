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
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->integer('repository_project_id')->nullable();
            $table->string('uuid')->unique();
            $table->string('name');

            $table->string('fqdn')->unique()->nullable();
            $table->string('config_hash')->nullable();

            $table->string('git_repository');
            $table->string('git_branch');
            $table->string('git_commit_sha')->default('HEAD');
            // TODO: remove this column, it is not used
            $table->string('git_full_url')->nullable();

            $table->string('docker_registry_image_name')->nullable();
            $table->string('docker_registry_image_tag')->nullable();

            $table->string('build_pack');
            $table->string('static_image')->default('nginx:alpine');

            $table->string('install_command')->nullable();
            $table->string('build_command')->nullable();
            $table->string('start_command')->nullable();

            $table->string('ports_exposes');
            $table->string('ports_mappings')->nullable();

            $table->string('base_directory')->default('/');
            $table->string('publish_directory')->nullable();

            $table->string('health_check_path')->default('/');
            $table->string('health_check_port')->nullable();
            $table->string('health_check_host')->default('localhost');
            $table->string('health_check_method')->default('GET');
            $table->integer('health_check_return_code')->default(200);
            $table->string('health_check_scheme')->default('http');
            $table->string('health_check_response_text')->nullable();
            $table->integer('health_check_interval')->default(5);
            $table->integer('health_check_timeout')->default(5);
            $table->integer('health_check_retries')->default(10);
            $table->integer('health_check_start_period')->default(5);

            $table->string('limits_memory')->default('0');
            $table->string('limits_memory_swap')->default('0');
            $table->integer('limits_memory_swappiness')->default(60);
            $table->string('limits_memory_reservation')->default('0');

            $table->string('limits_cpus')->default('0');
            $table->string('limits_cpuset')->nullable()->default('0');
            $table->integer('limits_cpu_shares')->default(1024);

            $table->string('status')->default('exited');
            $table->string('preview_url_template')->default('{{pr_id}}.{{domain}}');

            $table->nullableMorphs('destination');
            $table->nullableMorphs('source');

            $table->foreignId('private_key_id')->nullable();
            $table->foreignId('environment_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
