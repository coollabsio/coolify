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
            $table->text('sentinel_token')->nullable();
            $table->integer('sentinel_metrics_refresh_rate_seconds')->default(5);
            $table->integer('sentinel_metrics_history_days')->default(30);
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
            $table->dropColumn('sentinel_token');
            $table->dropColumn('sentinel_metrics_refresh_rate_seconds');
            $table->dropColumn('sentinel_metrics_history_days');
        });
    }
};
