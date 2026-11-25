<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('is_smart_triggers_enabled')->default(true);
        });
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_smart_triggers_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('is_smart_triggers_enabled');
        });
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('is_smart_triggers_enabled');
        });
    }
};
