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
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('redact_all_env_values_in_logs')->default(true);
        });
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('redact_all_env_values_in_logs')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('redact_all_env_values_in_logs');
        });
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('redact_all_env_values_in_logs');
        });
    }
};
