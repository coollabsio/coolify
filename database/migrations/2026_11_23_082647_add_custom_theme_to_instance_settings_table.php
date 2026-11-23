<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The default Custom theme of the instance: the share code "#sidebar,#selected,#accent,#background".
     */
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('custom_theme')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('custom_theme');
        });
    }
};
