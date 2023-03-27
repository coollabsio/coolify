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
        Schema::create('environmentables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('environment_id');
            $table->unsignedBigInteger('environmentable_id');
            $table->string('environmentable_type');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('environmentables');
    }
};
