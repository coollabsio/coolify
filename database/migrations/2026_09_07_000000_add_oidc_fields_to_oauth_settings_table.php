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
            $table->string('custom_label')->nullable();
            $table->string('scopes')->nullable();
            $table->boolean('use_pkce')->default(true);
            $table->unsignedSmallInteger('clock_skew_seconds')->default(60);
        });

        if (! DB::table('oauth_settings')->where('provider', 'oidc')->exists()) {
            DB::table('oauth_settings')->insert([
                'provider' => 'oidc',
                'enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
                'use_pkce',
                'clock_skew_seconds',
            ]);
        });
    }
};
