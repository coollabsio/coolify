<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_sboms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('image_id', 80);
            $table->string('platform', 32);
            $table->jsonb('packages');
            $table->jsonb('base_layer_ids');
            $table->boolean('packages_truncated')->default(false);
            $table->timestamp('last_used_at');

            $table->unique(['server_id', 'image_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_sboms');
    }
};
