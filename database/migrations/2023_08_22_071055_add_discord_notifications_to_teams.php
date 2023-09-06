<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('telegram_enabled')->default(false);
            $table->text('telegram_token')->nullable();
            $table->text('telegram_chat_id')->nullable();
            $table->boolean('telegram_notifications_test')->default(true);
            $table->boolean('telegram_notifications_deployments')->default(true);
            $table->boolean('telegram_notifications_status_changes')->default(true);
            $table->boolean('telegram_notifications_database_backups')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('telegram_enabled');
            $table->dropColumn('telegram_token');
            $table->dropColumn('telegram_chat_id');
            $table->dropColumn('telegram_notifications_test');
            $table->dropColumn('telegram_notifications_deployments');
            $table->dropColumn('telegram_notifications_status_changes');
            $table->dropColumn('telegram_notifications_database_backups');
        });
    }
};
