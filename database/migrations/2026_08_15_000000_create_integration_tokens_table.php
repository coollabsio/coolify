<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('name');
            $table->text('token');
            $table->json('capabilities');
            $table->timestamps();

            $table->index(['team_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_tokens');
    }
};
