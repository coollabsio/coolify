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
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('is_metrics_enabled');
        });
        Schema::table('server_settings', function (Blueprint $table) {
            $table->boolean('is_metrics_enabled')->default(false);
            $table->integer('metrics_refresh_rate_seconds')->default(5);
            $table->integer('metrics_history_days')->default(30);
            $table->string('metrics_token')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('is_metrics_enabled')->default(true);
        });
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn('is_metrics_enabled');
            $table->dropColumn('metrics_refresh_rate_seconds');
            $table->dropColumn('metrics_history_days');
            $table->dropColumn('metrics_token');
        });
    }
};
