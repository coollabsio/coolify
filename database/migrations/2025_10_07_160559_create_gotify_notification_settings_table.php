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
        Schema::create('gotify_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->boolean('gotify_enabled')->default(false);
            $table->text('gotify_url')->nullable();
            $table->text('gotify_token')->nullable();

            $table->boolean('deployment_success_gotify_notifications')->default(false);
            $table->boolean('deployment_failure_gotify_notifications')->default(true);
            $table->boolean('status_change_gotify_notifications')->default(false);
            $table->boolean('backup_success_gotify_notifications')->default(false);
            $table->boolean('backup_failure_gotify_notifications')->default(true);
            $table->boolean('scheduled_task_success_gotify_notifications')->default(false);
            $table->boolean('scheduled_task_failure_gotify_notifications')->default(true);
            $table->boolean('docker_cleanup_success_gotify_notifications')->default(false);
            $table->boolean('docker_cleanup_failure_gotify_notifications')->default(true);
            $table->boolean('server_disk_usage_gotify_notifications')->default(true);
            $table->boolean('server_reachable_gotify_notifications')->default(false);
            $table->boolean('server_unreachable_gotify_notifications')->default(true);
            $table->boolean('server_patch_gotify_notifications')->default(false);

            $table->unique(['team_id']);
        });
        $teams = DB::table('teams')->get();

        foreach ($teams as $team) {
            try {
                DB::table('gotify_notification_settings')->insert([
                    'team_id' => $team->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('Error creating gotify notification settings for existing teams: '.$e->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gotify_notification_settings');
    }
};
