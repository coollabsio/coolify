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
        Schema::create('scheduled_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->boolean('enabled')->default(true);
            $table->string('name');
            $table->string('command');
            $table->string('frequency');
            $table->string('container')->nullable();
            $table->timestamps();

            $table->foreignId('application_id')->nullable();
            $table->foreignId('service_id')->nullable();
            $table->foreignId('team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_tasks');
    }
};
