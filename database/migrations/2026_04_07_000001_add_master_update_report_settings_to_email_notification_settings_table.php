<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_notification_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('email_notification_settings', 'master_update_report_email_notifications')) {
                $table->boolean('master_update_report_email_notifications')->default(true);
            }
            if (! Schema::hasColumn('email_notification_settings', 'master_update_report_frequency')) {
                $table->string('master_update_report_frequency')->default('weekly');
            }
            if (! Schema::hasColumn('email_notification_settings', 'master_update_report_day')) {
                $table->string('master_update_report_day')->default('monday');
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_notification_settings', function (Blueprint $table) {
            foreach ([
                'master_update_report_email_notifications',
                'master_update_report_frequency',
                'master_update_report_day',
            ] as $column) {
                if (Schema::hasColumn('email_notification_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
