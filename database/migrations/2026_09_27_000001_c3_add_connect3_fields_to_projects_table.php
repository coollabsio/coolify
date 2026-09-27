<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connect3 fork: per-client site fields on projects (PRD section 4.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('client_slug')->nullable()->unique();
            $table->string('site_state')->default('staged');
            $table->json('live_domains')->nullable();
            $table->string('staging_auth_user')->nullable();
            $table->text('staging_auth_pass')->nullable();
            $table->string('ai_gateway_key_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['client_slug']);
            $table->dropColumn([
                'client_slug',
                'site_state',
                'live_domains',
                'staging_auth_user',
                'staging_auth_pass',
                'ai_gateway_key_id',
            ]);
        });
    }
};
