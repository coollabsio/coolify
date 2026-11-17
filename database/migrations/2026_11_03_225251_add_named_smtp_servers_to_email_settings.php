<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The smtp_* columns stay the SMTP server in use. smtp_server_name is its name, and
     * smtp_servers holds the other saved servers (encrypted, because they contain passwords).
     */
    public function up(): void
    {
        foreach (['instance_settings', 'email_notification_settings'] as $tableName) {
            if (Schema::hasColumn($tableName, 'smtp_servers')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->string('smtp_server_name')->nullable();
                $table->text('smtp_servers')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['instance_settings', 'email_notification_settings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['smtp_server_name', 'smtp_servers']);
            });
        }
    }
};
