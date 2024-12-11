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
        Schema::create('pushover_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->boolean('pushover_enabled')->default(false);
            $table->text('pushover_user_key')->nullable();
            $table->text('pushover_api_token')->nullable();

            $table->boolean('deployment_success_pushover_notifications')->default(false);
            $table->boolean('deployment_failure_pushover_notifications')->default(true);
            $table->boolean('status_change_pushover_notifications')->default(false);
            $table->boolean('backup_success_pushover_notifications')->default(false);
            $table->boolean('backup_failure_pushover_notifications')->default(true);
            $table->boolean('scheduled_task_success_pushover_notifications')->default(false);
            $table->boolean('scheduled_task_failure_pushover_notifications')->default(true);
            $table->boolean('docker_cleanup_success_pushover_notifications')->default(false);
            $table->boolean('docker_cleanup_failure_pushover_notifications')->default(true);
            $table->boolean('server_disk_usage_pushover_notifications')->default(true);
            $table->boolean('server_reachable_pushover_notifications')->default(false);
            $table->boolean('server_unreachable_pushover_notifications')->default(true);

            $table->unique(['team_id']);
        });
        $teams = DB::table('teams')->get();

        foreach ($teams as $team) {
            try {
                DB::table('pushover_notification_settings')->insert([
                    'team_id' => $team->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('Error creating pushover notification settings for existing teams: '.$e->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pushover_notification_settings');
    }
};
