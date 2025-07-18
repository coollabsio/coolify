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
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('telegram_notifications_scheduled_tasks')->default(true);
            $table->boolean('smtp_notifications_scheduled_tasks')->default(false)->after('smtp_notifications_status_changes');
            $table->boolean('discord_notifications_scheduled_tasks')->default(true)->after('discord_notifications_status_changes');
            $table->text('telegram_notifications_scheduled_tasks_thread_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('telegram_notifications_scheduled_tasks');
            $table->dropColumn('smtp_notifications_scheduled_tasks');
            $table->dropColumn('discord_notifications_scheduled_tasks');
            $table->dropColumn('telegram_notifications_scheduled_tasks_thread_id');
        });
    }
};
