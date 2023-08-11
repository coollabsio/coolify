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
        Schema::create('local_persistent_volumes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('mount_path');
            $table->string('host_path')->nullable();
            $table->string('container_id')->nullable();

            $table->nullableMorphs('resource');

            $table->unique(['name', 'resource_id', 'resource_type']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('local_persistent_volumes');
    }
};
