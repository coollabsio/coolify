<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('last_stop_notification_at')->nullable()->after('last_restart_at');
        });

        Schema::table('standalone_databases', function (Blueprint $table) {
            $table->timestamp('last_stop_notification_at')->nullable()->after('last_restart_at');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('last_stop_notification_at');
        });

        Schema::table('standalone_databases', function (Blueprint $table) {
            $table->dropColumn('last_stop_notification_at');
        });
    }
};
