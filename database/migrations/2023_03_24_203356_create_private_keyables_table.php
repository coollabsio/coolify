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
        Schema::create('private_keyables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('private_key_id');
            $table->unsignedBigInteger('private_keyable_id');
            $table->string('private_keyable_type');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('private_keyables');
    }
};
