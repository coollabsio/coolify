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
        Schema::table('server_settings', function (Blueprint $table) {
            $table->boolean('is_logdrain_custom_enabled')->default(false);
            $table->text('logdrain_custom_config')->nullable();
            $table->text('logdrain_custom_config_parser')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn('is_logdrain_custom_enabled');
            $table->dropColumn('logdrain_custom_config');
            $table->dropColumn('logdrain_custom_config_parser');
        });
    }
};
