<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->string('configuration_hash')->nullable()->after('docker_registry_image_tag');
            $table->json('configuration_snapshot')->nullable()->after('configuration_hash');
            $table->json('configuration_diff')->nullable()->after('configuration_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->dropColumn([
                'configuration_hash',
                'configuration_snapshot',
                'configuration_diff',
            ]);
        });
    }
};
