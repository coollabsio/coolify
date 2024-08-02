<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('pushover_enabled')->default(false);
            $table->text('pushover_token')->nullable();
            $table->text('pushover_user')->nullable();
            $table->boolean('pushover_notifications_test')->default(true);
            $table->boolean('pushover_notifications_deployments')->default(true);
            $table->boolean('pushover_notifications_status_changes')->default(true);
            $table->boolean('pushover_notifications_database_backups')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('pushover_enabled');
            $table->dropColumn('pushover_token');
            $table->dropColumn('pushover_user');
            $table->dropColumn('pushover_notifications_test');
            $table->dropColumn('pushover_notifications_deployments');
            $table->dropColumn('pushover_notifications_status_changes');
            $table->dropColumn('pushover_notifications_database_backups');
        });
    }
};
