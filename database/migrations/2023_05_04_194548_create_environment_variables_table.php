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
        Schema::create('environment_variables', function (Blueprint $table) {
            $table->id();

            $table->string('key');
            $table->string('value')->nullable();
            $table->boolean('is_build_time')->default(false);
            $table->boolean('is_preview')->default(false);

            $table->foreignId('application_id')->nullable();
            $table->foreignId('service_id')->nullable();
            $table->foreignId('database_id')->nullable();

            $table->unique(['key', 'application_id', 'is_build_time', 'is_preview']);
            $table->unique(['key', 'service_id', 'is_build_time', 'is_preview']);
            $table->unique(['key', 'database_id', 'is_build_time', 'is_preview']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('environment_variables');
    }
};
