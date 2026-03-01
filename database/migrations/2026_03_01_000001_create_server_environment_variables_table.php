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
        Schema::create('server_environment_variables', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('is_shown_once')->default(false);
            $table->boolean('is_literal')->default(false);
            $table->boolean('is_multiline')->default(false);
            $table->boolean('is_buildtime')->default(false);
            $table->foreignId('server_id')->constrained()->onDelete('cascade');
            $table->unique(['key', 'server_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_environment_variables');
    }
};
