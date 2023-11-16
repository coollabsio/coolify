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
            $table->boolean('high_disk_usage_notification_sent')->default(false);
            $table->renameColumn('unreachable_email_sent', 'unreachable_notification_sent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('high_disk_usage_notification_sent');
            $table->renameColumn('unreachable_notification_sent', 'unreachable_email_sent');
        });
    }
};
