<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resend gets its own sender. It starts as a copy of the current sender, so that nothing changes
     * for existing installs. Both columns use the same encrypted cast, so the stored values copy as they are.
     */
    public function up(): void
    {
        foreach (['instance_settings', 'email_notification_settings'] as $tableName) {
            if (Schema::hasColumn($tableName, 'resend_from_address')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->text('resend_from_address')->nullable();
                $table->text('resend_from_name')->nullable();
            });

            DB::table($tableName)->update([
                'resend_from_address' => DB::raw('smtp_from_address'),
                'resend_from_name' => DB::raw('smtp_from_name'),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['instance_settings', 'email_notification_settings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['resend_from_address', 'resend_from_name']);
            });
        }
    }
};
