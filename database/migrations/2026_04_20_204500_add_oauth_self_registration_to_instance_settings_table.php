<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('is_oauth_registration_enabled')->default(false)->after('is_registration_enabled');
            $table->boolean('disable_password_login_for_oauth_users')->default(false)->after('is_oauth_registration_enabled');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('oauth_provider')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['is_oauth_registration_enabled', 'disable_password_login_for_oauth_users']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('oauth_provider');
        });
    }
};
