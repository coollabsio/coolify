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
            $table->boolean('is_registration_enabled')->default(false);
            $table->boolean('disable_password_auth')->default(false);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('oauth_provider')->nullable()->index();
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
            $table->dropColumn(['is_registration_enabled', 'disable_password_auth']);
        });
    }
};
