<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('docker_registry_username')->nullable();
            $table->text('docker_registry_token')->nullable();
            $table->string('docker_registry_url')->nullable();
            $table->boolean('docker_use_custom_registry')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('docker_registry_username');
            $table->dropColumn('docker_registry_token');
            $table->dropColumn('docker_registry_url');
            $table->dropColumn('docker_use_custom_registry');
        });
    }
};