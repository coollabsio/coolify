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
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('registry_url');
            $table->string('username');
            $table->text('password');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'registry_url']);
            $table->index('server_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docker_registries');
    }
};
