<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'email_notification_settings',
        'discord_notification_settings',
        'slack_notification_settings',
        'telegram_notification_settings',
        'pushover_notification_settings',
        'webhook_notification_settings',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'failure_count')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->integer('failure_count')->default(0);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'failure_count')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('failure_count');
                });
            }
        }
    }
};
