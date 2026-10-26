<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_links', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->morphs('source');
            $table->morphs('target');
            $table->string('label', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['environment_id', 'source_type', 'source_id', 'target_type', 'target_id'], 'resource_links_unique_direction');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_links');
    }
};
