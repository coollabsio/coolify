<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->integer('retry_count')->default(0)->after('rollback');
            $table->integer('max_retries')->default(0)->after('retry_count');
        });

        Schema::table('application_settings', function (Blueprint $table) {
            $table->integer('max_deployment_retries')->default(2)->after('is_raw_compose_deployment_enabled');
            $table->boolean('auto_rollback_on_failure')->default(true)->after('max_deployment_retries');
        });
    }

    public function down(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->dropColumn(['retry_count', 'max_retries']);
        });

        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn(['max_deployment_retries', 'auto_rollback_on_failure']);
        });
    }
};
