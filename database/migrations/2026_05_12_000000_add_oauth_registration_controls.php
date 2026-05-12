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
        Schema::table('oauth_settings', function (Blueprint $table) {
            $table->boolean('is_registration_enabled')->default(false)->after('enabled');
            $table->boolean('is_password_login_disabled')->default(false)->after('is_registration_enabled');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('oauth_provider')->nullable()->after('password')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['oauth_provider']);
            $table->dropColumn('oauth_provider');
        });

        Schema::table('oauth_settings', function (Blueprint $table) {
            $table->dropColumn([
                'is_registration_enabled',
                'is_password_login_disabled',
            ]);
        });
    }
};
