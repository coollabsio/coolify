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
            $table->boolean('is_oauth_registration_enabled')->default(false)->after('is_registration_enabled');
            $table->boolean('is_oauth_only_auth_enabled')->default(false)->after('is_oauth_registration_enabled');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('oauth_provider')->nullable()->after('email');
            $table->boolean('is_oauth_only')->default(false)->after('oauth_provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['oauth_provider', 'is_oauth_only']);
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['is_oauth_registration_enabled', 'is_oauth_only_auth_enabled']);
        });
    }
};
