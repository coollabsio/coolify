<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_workloads', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('resource');
            $table->string('name');
            $table->string('desired_state')->default('running')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_workloads');
    }
};
