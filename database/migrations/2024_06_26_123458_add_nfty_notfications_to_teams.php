<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('ntfy_enabled')->default(false);
            $table->text('ntfy_url')->nullable();
            $table->text('ntfy_topic')->nullable();
            $table->text('ntfy_username')->nullable();
            $table->text('ntfy_password')->nullable();
            $table->boolean('ntfy_notifications_test')->default(true);
            $table->boolean('ntfy_notifications_deployments')->default(true);
            $table->boolean('ntfy_notifications_status_changes')->default(true);
            $table->boolean('ntfy_notifications_database_backups')->default(true);
            $table->boolean('ntfy_notifications_scheduled_tasks')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('ntfy_enabled');
            $table->dropColumn('ntfy_url');
            $table->dropColumn('ntfy_topic');
            $table->dropColumn('ntfy_username');
            $table->dropColumn('ntfy_password');
            $table->dropColumn('ntfy_notifications_test');
            $table->dropColumn('ntfy_notifications_deployments');
            $table->dropColumn('ntfy_notifications_status_changes');
            $table->dropColumn('ntfy_notifications_database_backups');
            $table->dropColumn('ntfy_notifications_scheduled_tasks');
        });
    }
};
