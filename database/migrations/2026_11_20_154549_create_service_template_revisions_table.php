<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_template_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->longText('docker_compose_raw');
            $table->string('template_reference_hash')->nullable();
            $table->timestamps();

            $table->index(['service_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_template_revisions');
    }
};
