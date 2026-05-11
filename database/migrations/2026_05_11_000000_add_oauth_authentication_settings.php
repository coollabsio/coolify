<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('instance_settings', 'is_oauth_registration_enabled')) {
                $table->boolean('is_oauth_registration_enabled')->default(false);
            }
            if (! Schema::hasColumn('instance_settings', 'is_password_authentication_enabled')) {
                $table->boolean('is_password_authentication_enabled')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            if (Schema::hasColumn('instance_settings', 'is_oauth_registration_enabled')) {
                $table->dropColumn('is_oauth_registration_enabled');
            }
            if (Schema::hasColumn('instance_settings', 'is_password_authentication_enabled')) {
                $table->dropColumn('is_password_authentication_enabled');
            }
        });
    }
};
