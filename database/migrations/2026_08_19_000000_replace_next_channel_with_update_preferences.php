<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('update_channel')->default('stable');
            $table->string('auto_update_scope')->default('minor');
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('next_channel');
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('next_channel')->default(false);
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['update_channel', 'auto_update_scope']);
        });
    }
};
