<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->string('docker_version')->nullable();
            $table->timestamp('docker_version_checked_at')->nullable();
            $table->string('compose_version')->nullable();
            $table->timestamp('compose_version_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn([
                'docker_version',
                'docker_version_checked_at',
                'compose_version',
                'compose_version_checked_at',
            ]);
        });
    }
};
