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
        Schema::create('github_apps', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');

            $table->string('organization')->nullable();
            $table->string('api_url');
            $table->string('html_url');
            $table->string('custom_user')->default('git');
            $table->integer('custom_port')->default(22);

            $table->integer('app_id')->nullable();
            $table->integer('installation_id')->nullable();
            $table->string('client_id')->nullable();
            $table->longText('client_secret')->nullable();
            $table->longText('webhook_secret')->nullable();

            $table->boolean('is_system_wide')->default(false);
            $table->boolean('is_public')->default(false);

            $table->foreignId('private_key_id')->nullable();
            $table->foreignId('team_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('github_apps');
    }
};
