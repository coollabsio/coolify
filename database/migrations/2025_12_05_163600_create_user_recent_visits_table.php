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
        Schema::create('user_recent_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('type');
            $table->string('icon')->nullable();
            $table->timestamp('visited_at');
            $table->timestamps();

            $table->index(['user_id', 'visited_at']);
            $table->unique(['user_id', 'url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_recent_visits');
    }
};
