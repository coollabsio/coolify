<?php

use App\Models\Team;
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
            $table->boolean('slack_enabled')->default(false);
            $table->string('slack_webhook_url')->nullable();
            $table->boolean('slack_notifications_test')->default(true);
            $table->boolean('slack_notifications_deployments')->default(true);
            $table->boolean('slack_notifications_status_changes')->default(true);
            $table->boolean('slack_notifications_database_backups')->default(true)->after('slack_notifications_status_changes');
            $table->boolean('slack_notifications_scheduled_tasks')->default(true)->after('slack_notifications_status_changes');

        });
        $teams = Team::all();
        foreach ($teams as $team) {
            $team->slack_enabled = data_get($team, 'slack.enabled', false);
            $team->slack_webhook_url = data_get($team, 'slack.webhook_url');
            $team->slack_notifications_test = data_get($team, 'slack_notifications.test', true);
            $team->slack_notifications_deployments = data_get($team, 'slack_notifications.deployments', true);
            $team->slack_notifications_status_changes = data_get($team, 'slack_notifications.status_changes', true);

            $team->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->schemalessAttributes('slack');
            $table->schemalessAttributes('slack_notifications');
        });
        $teams = Team::all();
        foreach ($teams as $team) {
            $team->slack = [
                'enabled' => $team->slack_enabled,
                'webhook_url' => $team->slack_webhook_url,
            ];
            $team->slack_notifications = [
                'test' => $team->slack_notifications_test,
                'deployments' => $team->slack_notifications_deployments,
                'status_changes' => $team->slack_notifications_status_changes,
            ];
            $team->save();
        }
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('slack_enabled');
            $table->dropColumn('slack_webhook_url');
            $table->dropColumn('slack_notifications_test');
            $table->dropColumn('slack_notifications_deployments');
            $table->dropColumn('slack_notifications_status_changes');
            $table->dropColumn('slack_notifications_scheduled_tasks');
            $table->dropColumn('slack_notifications_database_backups');
        });
    }
};
