<?php

use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\ScheduledVolumeBackup;
use App\Models\ServerSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schedules without a recorded occurrence keep next_run_at = null. The dispatcher calculates it on its first run.
     */
    public function up(): void
    {
        foreach (['scheduled_database_backups', 'scheduled_tasks', 'scheduled_volume_backups'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestampTz('next_run_at')->nullable();
                $table->index(['enabled', 'next_run_at']);
            });
        }

        Schema::table('server_settings', function (Blueprint $table) {
            $table->timestampTz('docker_cleanup_next_run_at')->nullable()->index();
        });

        $this->continueAfterLastScheduledOccurrences();

        Schema::dropIfExists('scheduled_job_states');
    }

    public function down(): void
    {
        Schema::create('scheduled_job_states', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('schedule_key')->unique();
            $table->timestampTz('last_scheduled_for')->nullable();
            $table->timestamps();
        });

        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropIndex(['docker_cleanup_next_run_at']);
            $table->dropColumn('docker_cleanup_next_run_at');
        });

        foreach (['scheduled_database_backups', 'scheduled_tasks', 'scheduled_volume_backups'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['enabled', 'next_run_at']);
                $table->dropColumn('next_run_at');
            });
        }
    }

    /**
     * Continue each schedule after the last occurrence that the previous dispatcher recorded, so
     * an occurrence that was due during the upgrade still runs late instead of being skipped.
     */
    public function continueAfterLastScheduledOccurrences(): void
    {
        if (! Schema::hasTable('scheduled_job_states')) {
            return;
        }

        DB::table('scheduled_job_states')->whereNotNull('last_scheduled_for')->orderBy('id')->chunkById(500, function ($states) {
            foreach ($states as $state) {
                try {
                    [$type, $id] = explode(':', $state->schedule_key, 2) + [null, null];
                    $schedule = match ($type) {
                        'scheduled-backup' => ScheduledDatabaseBackup::find($id),
                        'scheduled-task' => ScheduledTask::find($id),
                        'scheduled-volume-backup' => ScheduledVolumeBackup::find($id),
                        'docker-cleanup' => ServerSetting::query()->where('server_id', $id)->first(),
                        default => null,
                    };

                    if ($schedule instanceof ServerSetting) {
                        $schedule->forceFill(['docker_cleanup_next_run_at' => next_cron_run_at(
                            (string) $schedule->docker_cleanup_frequency, $schedule->server_timezone, Carbon::parse($state->last_scheduled_for),
                        )])->saveQuietly();
                    } elseif ($schedule?->enabled) {
                        $schedule->forceFill(['next_run_at' => $schedule->calculateNextRunAt(Carbon::parse($state->last_scheduled_for))])->saveQuietly();
                    }
                } catch (Throwable) {
                    // The dispatcher calculates the next run of this schedule on its first run.
                }
            }
        });
    }
};
