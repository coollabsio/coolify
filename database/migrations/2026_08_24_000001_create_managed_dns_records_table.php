<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_dns_records', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dns_provider_zone_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('resource');
            $table->string('provider_record_id');
            $table->string('type', 16);
            $table->string('name');
            $table->string('content');
            $table->timestamps();
            $table->unique(['dns_provider_zone_id', 'provider_record_id']);
            $table->index(['team_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_dns_records');
    }
};
