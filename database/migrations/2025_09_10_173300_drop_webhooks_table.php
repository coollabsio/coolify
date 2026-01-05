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
        Schema::dropIfExists('webhooks');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->string('type');
            $table->longText('payload');
            $table->longText('failure_reason')->nullable();
            $table->timestamps();
        });
    }
};
