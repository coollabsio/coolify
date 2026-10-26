<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_notification_settings', function (Blueprint $table) {
            $table->boolean('image_vulnerabilities_webhook_notifications')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('webhook_notification_settings', function (Blueprint $table) {
            $table->dropColumn('image_vulnerabilities_webhook_notifications');
        });
    }
};
