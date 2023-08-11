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
        Schema::create('gitlab_apps', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');

            $table->string('organization')->nullable();
            $table->string('api_url');
            $table->string('html_url');
            $table->integer('custom_port')->default(22);
            $table->string('custom_user')->default('git');
            $table->boolean('is_system_wide')->default(false);
            $table->boolean('is_public')->default(false);

            $table->integer('app_id')->nullable();
            $table->string('app_secret')->nullable();
            $table->integer('oauth_id')->nullable();
            $table->string('group_name')->nullable();
            $table->longText('public_key')->nullable();
            $table->longText('webhook_token')->nullable();
            $table->integer('deploy_key_id')->nullable();

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
        Schema::dropIfExists('gitlab_apps');
    }
};
