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
        if (! Schema::hasColumn('instance_settings', 'is_oauth_registration_enabled')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->boolean('is_oauth_registration_enabled')->default(false)->after('is_registration_enabled');
            });
        }

        if (! Schema::hasColumn('instance_settings', 'is_oauth_password_login_enabled')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->boolean('is_oauth_password_login_enabled')->default(true)->after('is_oauth_registration_enabled');
            });
        }

        if (! Schema::hasColumn('users', 'oauth_provider')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('oauth_provider')->nullable()->after('email');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'oauth_provider')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('oauth_provider');
            });
        }

        if (Schema::hasColumn('instance_settings', 'is_oauth_password_login_enabled')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->dropColumn('is_oauth_password_login_enabled');
            });
        }

        if (Schema::hasColumn('instance_settings', 'is_oauth_registration_enabled')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->dropColumn('is_oauth_registration_enabled');
            });
        }
    }
};
