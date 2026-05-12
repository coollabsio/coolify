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
            $table->boolean('allow_registration')->default(false)->after('enabled');
            $table->boolean('force_oauth_only')->default(false)->after('allow_registration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oauth_settings', function (Blueprint $table) {
            $table->dropColumn(['allow_registration', 'force_oauth_only']);
        });
    }
};
