<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('is_oauth_registration_enabled')->default(false)->after('is_registration_enabled');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('oauth_only')->default(false)->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('is_oauth_registration_enabled');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('oauth_only');
        });
    }
};
