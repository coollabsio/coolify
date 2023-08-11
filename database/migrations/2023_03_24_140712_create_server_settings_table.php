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
        Schema::create('server_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_part_of_swarm')->default(false);
            $table->boolean('is_jump_server')->default(false);
            $table->boolean('is_build_server')->default(false);
            $table->boolean('is_reachable')->default(false);
            $table->boolean('is_usable')->default(false);
            $table->foreignId('server_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_settings');
    }
};
