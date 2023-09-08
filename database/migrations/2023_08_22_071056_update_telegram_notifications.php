<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->text('telegram_notifications_test_message_thread_id')->nullable();
            $table->text('telegram_notifications_deployments_message_thread_id')->nullable();
            $table->text('telegram_notifications_status_changes_message_thread_id')->nullable();
            $table->text('telegram_notifications_database_backups_message_thread_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('telegram_message_thread_id');
            $table->dropColumn('telegram_notifications_test_message_thread_id');
            $table->dropColumn('telegram_notifications_deployments_message_thread_id');
            $table->dropColumn('telegram_notifications_status_changes_message_thread_id');
            $table->dropColumn('telegram_notifications_database_backups_message_thread_id');
        });
    }
};
