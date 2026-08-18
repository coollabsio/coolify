<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_previews', function (Blueprint $table) {
            $table->string('docker_registry_image_tag')->nullable()->after('docker_compose_domains');
        });

        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->string('docker_registry_image_tag')->nullable()->after('pull_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('application_previews', function (Blueprint $table) {
            $table->dropColumn('docker_registry_image_tag');
        });

        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->dropColumn('docker_registry_image_tag');
        });
    }
};
