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
        if (! Schema::hasTable('ntfy_notification_settings')) {
            Schema::create('ntfy_notification_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();

                $table->boolean('ntfy_enabled')->default(false);
                $table->text('ntfy_url')->nullable();
                $table->text('ntfy_topic')->nullable();
                $table->text('ntfy_token')->nullable();
                $table->text('ntfy_username')->nullable();
                $table->text('ntfy_password')->nullable();
                $table->string('ntfy_auth_method')->default('basic');

                // Per-severity priority settings (1=min, 2=low, 3=default, 4=high, 5=max/urgent)
                $table->tinyInteger('ntfy_priority_success_events')->default(2);
                $table->tinyInteger('ntfy_priority_info_events')->default(3);
                $table->tinyInteger('ntfy_priority_warning_events')->default(4);
                $table->tinyInteger('ntfy_priority_error_events')->default(5);

                $table->boolean('deployment_success_ntfy_notifications')->default(false);
                $table->boolean('deployment_failure_ntfy_notifications')->default(true);
                $table->boolean('status_change_ntfy_notifications')->default(false);
                $table->boolean('backup_success_ntfy_notifications')->default(false);
                $table->boolean('backup_failure_ntfy_notifications')->default(true);
                $table->boolean('scheduled_task_success_ntfy_notifications')->default(false);
                $table->boolean('scheduled_task_failure_ntfy_notifications')->default(true);
                $table->boolean('docker_cleanup_success_ntfy_notifications')->default(false);
                $table->boolean('docker_cleanup_failure_ntfy_notifications')->default(true);
                $table->boolean('server_disk_usage_ntfy_notifications')->default(true);
                $table->boolean('server_reachable_ntfy_notifications')->default(false);
                $table->boolean('server_unreachable_ntfy_notifications')->default(true);
                $table->boolean('server_patch_ntfy_notifications')->default(false);
                $table->boolean('traefik_outdated_ntfy_notifications')->default(true);

                $table->unique(['team_id']);
            });
        }

        DB::table('teams')->chunkById(100, function ($teams) {
            foreach ($teams as $team) {
                try {
                    $exists = DB::table('ntfy_notification_settings')
                        ->where('team_id', $team->id)
                        ->exists();

                    if (! $exists) {
                        DB::table('ntfy_notification_settings')->insert([
                            'team_id' => $team->id,
                            'ntfy_enabled' => false,
                            'ntfy_url' => null,
                            'ntfy_topic' => null,
                            'ntfy_token' => null,
                            'ntfy_username' => null,
                            'ntfy_password' => null,
                            'ntfy_auth_method' => 'basic',
                            'ntfy_priority_success_events' => 2,
                            'ntfy_priority_info_events' => 3,
                            'ntfy_priority_warning_events' => 4,
                            'ntfy_priority_error_events' => 5,
                            'deployment_success_ntfy_notifications' => false,
                            'deployment_failure_ntfy_notifications' => true,
                            'status_change_ntfy_notifications' => false,
                            'backup_success_ntfy_notifications' => false,
                            'backup_failure_ntfy_notifications' => true,
                            'scheduled_task_success_ntfy_notifications' => false,
                            'scheduled_task_failure_ntfy_notifications' => true,
                            'docker_cleanup_success_ntfy_notifications' => false,
                            'docker_cleanup_failure_ntfy_notifications' => true,
                            'server_disk_usage_ntfy_notifications' => true,
                            'server_reachable_ntfy_notifications' => false,
                            'server_unreachable_ntfy_notifications' => true,
                            'server_patch_ntfy_notifications' => false,
                            'traefik_outdated_ntfy_notifications' => true,
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::error('Error creating ntfy notification settings for team '.$team->id.': '.$e->getMessage());
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ntfy_notification_settings');
    }
};
