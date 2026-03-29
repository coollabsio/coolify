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
            // Allow users to self-register via OAuth2 even when general registration is disabled
            $table->boolean('is_oauth_registration_enabled')->default(false)->after('is_registration_enabled');
            // Restrict OAuth2-linked users to OAuth2 login only (block password auth)
            $table->boolean('is_oauth_login_only')->default(false)->after('is_oauth_registration_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['is_oauth_registration_enabled', 'is_oauth_login_only']);
        });
    }
};
