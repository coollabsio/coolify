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
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('server_id')->nullable();
            $table->longText('description')->nullable();
            $table->longText('docker_compose_raw');
            $table->longText('docker_compose')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('server_id');
            $table->dropColumn('description');
            $table->dropColumn('docker_compose_raw');
            $table->dropColumn('docker_compose');
        });
    }
};
