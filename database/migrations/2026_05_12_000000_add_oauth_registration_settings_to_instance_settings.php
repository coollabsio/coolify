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
        Schema::table('instance_settings', function (Blueprint $code) {
            $code->boolean('is_oauth_registration_enabled')->default(true);
            $code->boolean('is_force_oauth_login_enabled')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $code) {
            $code->dropColumn('is_oauth_registration_enabled');
            $code->dropColumn('is_force_oauth_login_enabled');
        });
    }
};
