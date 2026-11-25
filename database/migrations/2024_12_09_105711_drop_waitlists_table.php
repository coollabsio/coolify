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
        Schema::dropIfExists('waitlists');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('waitlists', function (Blueprint $table) {
            $table->id();
            $table->string('uuid');
            $table->string('type');
            $table->string('email')->unique();
            $table->boolean('verified')->default(false);
            $table->timestamps();
        });
    }
};
