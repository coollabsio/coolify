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
        if (! Schema::hasColumn('email_notification_settings', 'deployment_commit_details_email_notifications')) {
            Schema::table('email_notification_settings', function (Blueprint $table) {
                $table->boolean('deployment_commit_details_email_notifications')->default(false);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('email_notification_settings', 'deployment_commit_details_email_notifications')) {
            Schema::table('email_notification_settings', function (Blueprint $table) {
                $table->dropColumn('deployment_commit_details_email_notifications');
            });
        }
    }
};
