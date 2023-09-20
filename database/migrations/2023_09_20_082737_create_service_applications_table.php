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
        Schema::create('service_applications', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');

            $table->string('fqdn')->unique()->nullable();

            $table->string('ports_exposes')->nullable();
            $table->string('ports_mappings')->nullable();

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

            $table->string('status')->default('exited');

            $table->foreignId('service_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_applications');
    }
};
