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
        // Create table if it doesn't exist
        if (! Schema::hasTable('webhook_notification_settings')) {
            Schema::create('webhook_notification_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();

                $table->boolean('webhook_enabled')->default(false);
                $table->text('webhook_url')->nullable();

                $table->boolean('deployment_success_webhook_notifications')->default(false);
                $table->boolean('deployment_failure_webhook_notifications')->default(true);
                $table->boolean('status_change_webhook_notifications')->default(false);
                $table->boolean('backup_success_webhook_notifications')->default(false);
                $table->boolean('backup_failure_webhook_notifications')->default(true);
                $table->boolean('scheduled_task_success_webhook_notifications')->default(false);
                $table->boolean('scheduled_task_failure_webhook_notifications')->default(true);
                $table->boolean('docker_cleanup_success_webhook_notifications')->default(false);
                $table->boolean('docker_cleanup_failure_webhook_notifications')->default(true);
                $table->boolean('server_disk_usage_webhook_notifications')->default(true);
                $table->boolean('server_reachable_webhook_notifications')->default(false);
                $table->boolean('server_unreachable_webhook_notifications')->default(true);
                $table->boolean('server_patch_webhook_notifications')->default(false);
                $table->boolean('traefik_outdated_webhook_notifications')->default(true);

                $table->unique(['team_id']);
            });
        }

        // Populate webhook notification settings for existing teams (only if they don't already have settings)
        DB::table('teams')->chunkById(100, function ($teams) {
            foreach ($teams as $team) {
                try {
                    // Check if settings already exist for this team
                    $exists = DB::table('webhook_notification_settings')
                        ->where('team_id', $team->id)
                        ->exists();

                    if (! $exists) {
                        // Only insert if no settings exist - don't overwrite existing preferences
                        DB::table('webhook_notification_settings')->insert([
                            'team_id' => $team->id,
                            'webhook_enabled' => false,
                            'webhook_url' => null,
                            'deployment_success_webhook_notifications' => false,
                            'deployment_failure_webhook_notifications' => true,
                            'status_change_webhook_notifications' => false,
                            'backup_success_webhook_notifications' => false,
                            'backup_failure_webhook_notifications' => true,
                            'scheduled_task_success_webhook_notifications' => false,
                            'scheduled_task_failure_webhook_notifications' => true,
                            'docker_cleanup_success_webhook_notifications' => false,
                            'docker_cleanup_failure_webhook_notifications' => true,
                            'server_disk_usage_webhook_notifications' => true,
                            'server_reachable_webhook_notifications' => false,
                            'server_unreachable_webhook_notifications' => true,
                            'server_patch_webhook_notifications' => false,
                            'traefik_outdated_webhook_notifications' => true,
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::error('Error creating webhook notification settings for team '.$team->id.': '.$e->getMessage());
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_notification_settings');
    }
};
