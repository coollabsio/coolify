<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('slack_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->boolean('slack_enabled')->default(false);
            $table->text('slack_webhook_url')->nullable();

            $table->boolean('deployment_success_slack_notifications')->default(false);
            $table->boolean('deployment_failure_slack_notifications')->default(true);
            $table->boolean('status_change_slack_notifications')->default(false);
            $table->boolean('backup_success_slack_notifications')->default(false);
            $table->boolean('backup_failure_slack_notifications')->default(true);
            $table->boolean('scheduled_task_success_slack_notifications')->default(false);
            $table->boolean('scheduled_task_failure_slack_notifications')->default(true);
            $table->boolean('docker_cleanup_success_slack_notifications')->default(false);
            $table->boolean('docker_cleanup_failure_slack_notifications')->default(true);
            $table->boolean('server_disk_usage_slack_notifications')->default(true);
            $table->boolean('server_reachable_slack_notifications')->default(false);
            $table->boolean('server_unreachable_slack_notifications')->default(true);

            $table->unique(['team_id']);
        });
        $teams = DB::table('teams')->get();

        foreach ($teams as $team) {
            try {
                DB::table('slack_notification_settings')->insert([
                    'team_id' => $team->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('Error creating slack notification settings for existing teams: '.$e->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_notification_settings');
    }
};
