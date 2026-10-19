<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gitea_apps', function (Blueprint $table) {
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

            $table->longText('client_id')->nullable();
            $table->longText('client_secret')->nullable();
            $table->longText('access_token')->nullable();
            $table->longText('refresh_token')->nullable();
            $table->integer('expires_at')->nullable();
            $table->string('redirect_uri')->nullable();
            $table->longText('webhook_secret')->nullable();

            $table->foreignId('private_key_id')->nullable();
            $table->foreignId('team_id');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gitea_apps');
    }
};
