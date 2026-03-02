<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('is_oauth_registration_enabled')->default(false)->after('is_registration_enabled');
            $table->boolean('force_oauth_login')->default(false)->after('is_oauth_registration_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['is_oauth_registration_enabled', 'force_oauth_login']);
        });
    }
};
