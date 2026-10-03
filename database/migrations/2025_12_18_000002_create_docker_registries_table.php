<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docker_registries', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('registry_url')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->foreignId('team_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docker_registries');
    }
};
