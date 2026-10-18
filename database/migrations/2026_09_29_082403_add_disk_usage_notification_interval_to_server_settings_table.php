<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->integer('server_disk_usage_notification_interval_hours')->default(24);
        });
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn('server_disk_usage_notification_interval_hours');
        });
    }
};
