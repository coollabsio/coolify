<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_provider_zones', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('integration_token_id')->constrained()->cascadeOnDelete();
            $table->string('provider_zone_id');
            $table->string('name');
            $table->string('account_id')->nullable();
            $table->string('account_name')->nullable();
            $table->timestamps();
            $table->unique(['integration_token_id', 'provider_zone_id']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_provider_zones');
    }
};
