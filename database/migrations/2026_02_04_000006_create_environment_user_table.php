<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the environment_user pivot table for environment-level
     * permission overrides. By default, permissions cascade from projects,
     * but this table allows more granular control when needed.
     */
    public function up(): void
    {
        Schema::create('environment_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('permissions')->default('{}');
            $table->timestamps();

            $table->unique(['environment_id', 'user_id']);
            $table->index('user_id');
            $table->index('environment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('environment_user');
    }
};
