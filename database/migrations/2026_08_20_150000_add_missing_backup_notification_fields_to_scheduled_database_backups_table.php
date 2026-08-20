<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->unsignedInteger('missing_backup_notification_days')->default(0);
            $table->timestamp('missing_backup_notification_sent_at')->nullable();
            $table->timestamp('last_execution_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn([
                'missing_backup_notification_days',
                'missing_backup_notification_sent_at',
                'last_execution_at',
            ]);
        });
    }
};
