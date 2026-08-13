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
            $table->boolean('is_traffic_analytics_enabled')->default(false);
            $table->text('geoip_maxmind_license_key')->nullable();
            $table->integer('traffic_topn')->default(50);
            $table->integer('traffic_sample_threshold')->default(0);
            $table->integer('traffic_retention_1h_days')->default(30);
            $table->integer('traffic_retention_1d_days')->default(395);
            $table->boolean('is_geoip_enabled')->default(true);
            $table->integer('geoip_refresh_days')->default(30);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn([
                'is_traffic_analytics_enabled',
                'geoip_maxmind_license_key',
                'traffic_topn',
                'traffic_sample_threshold',
                'traffic_retention_1h_days',
                'traffic_retention_1d_days',
                'is_geoip_enabled',
                'geoip_refresh_days',
            ]);
        });
    }
};
