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
        Schema::create('origin_apps', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('api_url')->default('https://api.cursor.com/v1/origin');
            $table->string('html_url')->default('https://origin.cursor.com');
            $table->string('app_id')->nullable();
            $table->string('installation_id')->nullable();
            $table->string('namespace_slug')->nullable();
            $table->text('public_key')->nullable();
            $table->text('private_key')->nullable();
            $table->boolean('is_system_wide')->default(false);
            $table->foreignId('team_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('origin_apps');
    }
};
