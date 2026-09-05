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
        Schema::table('webhook_notification_settings', function (Blueprint $table) {
            $table->boolean('restart_limit_reached_webhook_notifications')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_notification_settings', function (Blueprint $table) {
            $table->dropColumn('restart_limit_reached_webhook_notifications');
        });
    }
};
