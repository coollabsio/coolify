<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The registry login of a cluster application: the pull login of an image application, or the
     * push login of a Git application. A Git application also pushes each build to the v4
     * "Docker registry image name" and tag.
     */
    public function up(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->foreignId('registry_credential_id')->nullable()->constrained('registry_credentials')->nullOnDelete();
            $table->string('docker_registry_image_name')->nullable();
            $table->string('docker_registry_image_tag')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registry_credential_id');
            $table->dropColumn(['docker_registry_image_name', 'docker_registry_image_tag']);
        });
    }
};
