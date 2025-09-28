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
        Schema::create('matrix_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->boolean('matrix_enabled')->default(false);
            $table->text('matrix_homeserver_url')->nullable();
            $table->text('matrix_room_id')->nullable();
            $table->text('matrix_access_token')->nullable();
            $table->string('matrix_friendly_name')->nullable();

            $table->boolean('deployment_success_matrix_notifications')->default(false);
            $table->boolean('deployment_failure_matrix_notifications')->default(true);
            $table->boolean('status_change_matrix_notifications')->default(false);
            $table->boolean('backup_success_matrix_notifications')->default(false);
            $table->boolean('backup_failure_matrix_notifications')->default(true);
            $table->boolean('scheduled_task_success_matrix_notifications')->default(false);
            $table->boolean('scheduled_task_failure_matrix_notifications')->default(true);
            $table->boolean('docker_cleanup_success_matrix_notifications')->default(false);
            $table->boolean('docker_cleanup_failure_matrix_notifications')->default(true);
            $table->boolean('server_disk_usage_matrix_notifications')->default(true);
            $table->boolean('server_reachable_matrix_notifications')->default(false);
            $table->boolean('server_unreachable_matrix_notifications')->default(true);
            $table->boolean('server_patch_matrix_notifications')->default(false);

            $table->unique(['team_id']);
        });

        $teams = DB::table('teams')->get();

        foreach ($teams as $team) {
            try {
                DB::table('matrix_notification_settings')->insert([
                    'team_id' => $team->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('Error creating matrix notification settings for existing teams: '.$e->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matrix_notification_settings');
    }
};