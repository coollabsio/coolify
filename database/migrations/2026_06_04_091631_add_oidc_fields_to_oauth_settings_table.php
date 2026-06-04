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
            $table->string('custom_label')->nullable();
            $table->string('scopes')->nullable();
            $table->boolean('allow_registration')->default(true);
            $table->boolean('require_email_verified')->default(true);
            $table->boolean('use_pkce')->default(true);
            $table->unsignedSmallInteger('clock_skew_seconds')->default(60);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oauth_settings', function (Blueprint $table) {
            $table->dropColumn([
                'custom_label',
                'scopes',
                'allow_registration',
                'require_email_verified',
                'use_pkce',
                'clock_skew_seconds',
            ]);
        });
    }
};
