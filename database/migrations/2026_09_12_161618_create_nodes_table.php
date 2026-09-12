<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('private_key_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('role')->default('worker')->index();
            $table->string('ip');
            $table->unsignedInteger('port')->default(22);
            $table->string('user')->default('root');
            $table->text('sentinel_token')->nullable();
            $table->string('sentinel_url')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_reachable')->default(false);
            $table->boolean('is_usable')->default(false);
            $table->text('validation_logs')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
