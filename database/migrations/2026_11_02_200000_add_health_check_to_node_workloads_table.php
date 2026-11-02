<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The health check settings of an application in v4. The check is off by default, so the
     * container keeps the HEALTHCHECK of its image.
     */
    public function up(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->boolean('health_check_enabled')->default(false);
            $table->string('health_check_type')->default('http');
            $table->text('health_check_command')->nullable();
            $table->string('health_check_method')->default('GET');
            $table->string('health_check_scheme')->default('http');
            $table->string('health_check_host')->default('localhost');
            $table->string('health_check_port')->nullable();
            $table->string('health_check_path')->default('/');
            $table->integer('health_check_return_code')->default(200);
            $table->string('health_check_response_text')->nullable();
            $table->integer('health_check_interval')->default(5);
            $table->integer('health_check_timeout')->default(5);
            $table->integer('health_check_retries')->default(10);
            $table->integer('health_check_start_period')->default(5);
            $table->boolean('custom_healthcheck_found')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropColumn([
                'health_check_enabled',
                'health_check_type',
                'health_check_command',
                'health_check_method',
                'health_check_scheme',
                'health_check_host',
                'health_check_port',
                'health_check_path',
                'health_check_return_code',
                'health_check_response_text',
                'health_check_interval',
                'health_check_timeout',
                'health_check_retries',
                'health_check_start_period',
                'custom_healthcheck_found',
            ]);
        });
    }
};
