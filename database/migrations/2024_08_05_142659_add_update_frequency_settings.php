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
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('auto_update_frequency')->default('0 0 * * *');
            $table->string('update_check_frequency')->default('0 * * * *');
            $table->boolean('new_version_available')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('update_check_frequency');
            $table->dropColumn('auto_update_frequency');
            $table->dropColumn('new_version_available');
        });
    }
};
