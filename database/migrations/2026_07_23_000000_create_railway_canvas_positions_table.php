<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('railway_canvas_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('resource_uuid')->index();
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            $table->timestamps();

            $table->unique(['environment_id', 'resource_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('railway_canvas_positions');
    }
};
