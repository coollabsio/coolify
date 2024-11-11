<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->foreignId('docker_registry_id')->nullable();
            $table->boolean('docker_use_custom_registry')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('docker_registry_id');
            $table->dropColumn('docker_use_custom_registry');
        });
    }
};
