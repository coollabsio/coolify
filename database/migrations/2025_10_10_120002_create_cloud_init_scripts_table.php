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
        // Check if table already exists (handles upgrades from v444 where this migration
        // was named 2025_10_10_120000_create_cloud_init_scripts_table.php)
        if (Schema::hasTable('cloud_init_scripts')) {
            return;
        }

        Schema::create('cloud_init_scripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('script'); // Encrypted in the model
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cloud_init_scripts');
    }
};
