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
            $table->dropColumn('metrics_token');
            $table->dropColumn('metrics_refresh_rate_seconds');
            $table->dropColumn('metrics_history_days');
            $table->dropColumn('is_server_api_enabled');

            $table->boolean('is_sentinel_enabled')->default(false);
            $table->text('sentinel_token')->nullable();
            $table->integer('sentinel_metrics_refresh_rate_seconds')->default(10);
            $table->integer('sentinel_metrics_history_days')->default(7);
            $table->integer('sentinel_push_interval_seconds')->default(60);
            $table->string('sentinel_custom_url')->nullable();
        });
        Schema::table('servers', function (Blueprint $table) {
            $table->dateTime('sentinel_updated_at')->default(now());
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->string('metrics_token')->nullable();
            $table->integer('metrics_refresh_rate_seconds')->default(5);
            $table->integer('metrics_history_days')->default(30);
            $table->boolean('is_server_api_enabled')->default(false);

            $table->dropColumn('is_sentinel_enabled');
            $table->dropColumn('sentinel_token');
            $table->dropColumn('sentinel_metrics_refresh_rate_seconds');
            $table->dropColumn('sentinel_metrics_history_days');
            $table->dropColumn('sentinel_push_interval_seconds');
            $table->dropColumn('sentinel_custom_url');
        });
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('sentinel_updated_at');
        });
    }
};
