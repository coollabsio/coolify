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
        Schema::create('github_runner_sources', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('runner_label')->index();

            $table->integer('app_id')->nullable();
            $table->integer('installation_id')->nullable();
            $table->string('client_id')->nullable();
            $table->longText('client_secret')->nullable();
            $table->longText('webhook_secret')->nullable();

            $table->string('organization')->nullable();
            $table->boolean('is_organization_level')->default(true);

            $table->json('permissions')->nullable();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('github_runner_sources');
    }
};
