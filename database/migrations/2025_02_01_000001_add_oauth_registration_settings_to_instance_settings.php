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
            // Allow OAuth users to self-register even when general registration is disabled
            $table->boolean('is_oauth_registration_enabled')->default(false)->after('is_registration_enabled');
            // Force OAuth users to only use OAuth for login (no password authentication allowed)
            $table->boolean('is_oauth_only_login_forced')->default(false)->after('is_oauth_registration_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('is_oauth_registration_enabled');
            $table->dropColumn('is_oauth_only_login_forced');
        });
    }
};
