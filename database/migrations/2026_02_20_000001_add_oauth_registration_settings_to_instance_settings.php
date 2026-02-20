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
        Schema::table('instance_settings', function (Blueprint $table) {
            // When true, users can create accounts via OAuth even if general
            // self-registration (is_registration_enabled) is disabled.
            $table->boolean('is_oauth_registration_enabled')->default(true)->after('is_registration_enabled');

            // When true, users who sign in via OAuth are prevented from setting
            // or using a local password (forces OAuth-only access, useful with
            // identity providers like Authentik to centralise access control).
            $table->boolean('oauth_force_only')->default(false)->after('is_oauth_registration_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['is_oauth_registration_enabled', 'oauth_force_only']);
        });
    }
};
