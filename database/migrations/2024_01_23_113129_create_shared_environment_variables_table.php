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
        Schema::create('shared_environment_variables', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('value')->nullable();
            $table->boolean('is_shown_once')->default(false);
            $table->enum('type', ['team', 'project', 'environment'])->default('team');

            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('environment_id')->nullable()->constrained()->onDelete('cascade');
            $table->unique(['key', 'project_id', 'team_id']);
            $table->unique(['key', 'environment_id', 'team_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shared_environment_variables');
    }
};
