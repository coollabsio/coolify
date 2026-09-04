<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->boolean('can_build')->default(true);
            $table->timestamps();

            $table->unique(['server_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_team');
    }
};
