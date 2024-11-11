<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('docker_registries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // docker_hub, gcr, ghcr, quay, custom
            $table->string('url')->nullable();
            $table->string('username')->nullable();
            $table->text('token')->nullable();
            $table->timestamps();
        });

        // Add foreign key constraint
        Schema::table('applications', function (Blueprint $table) {
            $table->foreign('docker_registry_id')
                ->references('id')
                ->on('docker_registries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropForeign(['docker_registry_id']);
        });

        Schema::dropIfExists('docker_registries');
    }
};
