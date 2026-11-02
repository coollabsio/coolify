<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->boolean('is_auto_security_updates_enabled')->default(false);
            $table->string('auto_security_updates_time')->default('03:00');
        });
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn(['is_auto_security_updates_enabled', 'auto_security_updates_time']);
        });
    }
};
