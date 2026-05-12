<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('oauth_settings', function (Blueprint $table) {
            $table->boolean('allow_registration')->default(false)->after('enabled');
            $table->boolean('disable_password_login')->default(false)->after('allow_registration');
        });

        if (DB::table('instance_settings')->where('id', 0)->value('is_registration_enabled')) {
            DB::table('oauth_settings')->update(['allow_registration' => true]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('oauth_provider')->nullable()->after('email');
            $table->boolean('is_password_login_enabled')->default(true)->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['oauth_provider', 'is_password_login_enabled']);
        });

        Schema::table('oauth_settings', function (Blueprint $table) {
            $table->dropColumn(['allow_registration', 'disable_password_login']);
        });
    }
};
