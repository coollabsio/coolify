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
        Schema::create('microsoft_teams_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->boolean('microsoft_teams_enabled')->default(false);
            $table->text('microsoft_teams_webhook_url')->nullable();

            $table->boolean('deployment_success_microsoft_teams_notifications')->default(false);
            $table->boolean('deployment_failure_microsoft_teams_notifications')->default(true);
            $table->boolean('status_change_microsoft_teams_notifications')->default(false);
            $table->boolean('restart_limit_reached_microsoft_teams_notifications')->default(true);
            $table->boolean('backup_success_microsoft_teams_notifications')->default(false);
            $table->boolean('backup_failure_microsoft_teams_notifications')->default(true);
            $table->boolean('scheduled_task_success_microsoft_teams_notifications')->default(false);
            $table->boolean('scheduled_task_failure_microsoft_teams_notifications')->default(true);
            $table->boolean('docker_cleanup_success_microsoft_teams_notifications')->default(false);
            $table->boolean('docker_cleanup_failure_microsoft_teams_notifications')->default(true);
            $table->boolean('server_disk_usage_microsoft_teams_notifications')->default(true);
            $table->boolean('server_reachable_microsoft_teams_notifications')->default(false);
            $table->boolean('server_unreachable_microsoft_teams_notifications')->default(true);
            $table->boolean('server_patch_microsoft_teams_notifications')->default(true);
            $table->boolean('traefik_outdated_microsoft_teams_notifications')->default(true);

            $table->unique(['team_id']);
        });

        $teams = DB::table('teams')->get();

        foreach ($teams as $team) {
            try {
                DB::table('microsoft_teams_notification_settings')->insert([
                    'team_id' => $team->id,
                ]);
            } catch (Throwable $e) {
                Log::error('Error creating Microsoft Teams notification settings for existing teams: '.$e->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('microsoft_teams_notification_settings');
    }
};
