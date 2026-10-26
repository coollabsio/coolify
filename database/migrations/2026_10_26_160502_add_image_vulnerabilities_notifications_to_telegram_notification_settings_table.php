<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_notification_settings', function (Blueprint $table) {
            $table->boolean('image_vulnerabilities_telegram_notifications')->default(false);
            $table->text('telegram_notifications_image_vulnerabilities_thread_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_notification_settings', function (Blueprint $table) {
            $table->dropColumn('image_vulnerabilities_telegram_notifications');
            $table->dropColumn('telegram_notifications_image_vulnerabilities_thread_id');
        });
    }
};
