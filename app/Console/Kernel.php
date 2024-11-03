<?php

namespace App\Console;

use App\Actions\Server\ResourcesCheck;
use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\CheckForUpdatesJob;
use App\Jobs\CheckHelperImageJob;
use App\Jobs\CleanupInstanceStuffsJob;
use App\Jobs\CleanupStaleMultiplexedConnections;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\DockerCleanupJob;
use App\Jobs\PullTemplatesFromCDN;
use App\Jobs\ScheduledTaskJob;
use App\Jobs\ServerCheckJob;
use App\Jobs\ServerCleanupMux;
use App\Jobs\ServerStorageCheckJob;
use App\Jobs\UpdateCoolifyJob;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon;

class Kernel extends ConsoleKernel
{
    private $allServers;

    private InstanceSettings $settings;

    protected function schedule(Schedule $schedule): void
    {
        $this->allServers = Server::where('ip', '!=', '1.2.3.4')->get();

        $this->settings = instanceSettings();

        $schedule->job(new CleanupStaleMultiplexedConnections)->hourly();

        if (isDev()) {
            // Instance Jobs
            $schedule->command('horizon:snapshot')->everyMinute();
            $schedule->job(new CleanupInstanceStuffsJob)->everyMinute()->onOneServer();
            $schedule->job(new CheckHelperImageJob)->everyFiveMinutes()->onOneServer();

            // Server Jobs
            $this->checkResources($schedule);

            $this->checkScheduledBackups($schedule);
            $this->checkScheduledTasks($schedule);

            $schedule->command('uploads:clear')->everyTwoMinutes();

        } else {
            // Instance Jobs
            $schedule->command('horizon:snapshot')->everyFiveMinutes();
            $schedule->command('cleanup:unreachable-servers')->daily()->onOneServer();
            $schedule->job(new PullTemplatesFromCDN)->cron($this->settings->update_check_frequency)->timezone($this->settings->instance_timezone)->onOneServer();
            $schedule->job(new CleanupInstanceStuffsJob)->everyTwoMinutes()->onOneServer();
            $this->scheduleUpdates($schedule);

            // Server Jobs
            $this->checkResources($schedule);

            $this->pullImages($schedule);

            $this->checkScheduledBackups($schedule);
            $this->checkScheduledTasks($schedule);

            $schedule->command('cleanup:database --yes')->daily();
            $schedule->command('uploads:clear')->everyTwoMinutes();
        }
    }

    private function pullImages($schedule): void
    {
        $servers = $this->allServers->whereRelation('settings', 'is_usable', true)->whereRelation('settings', 'is_reachable', true);
        foreach ($servers as $server) {
            if ($server->isSentinelEnabled()) {
                $schedule->job(function () use ($server) {
                    CheckAndStartSentinelJob::dispatch($server);
                })->cron($this->settings->update_check_frequency)->timezone($this->settings->instance_timezone)->onOneServer();
            }
        }
        $schedule->job(new CheckHelperImageJob)
            ->cron($this->settings->update_check_frequency)
            ->timezone($this->settings->instance_timezone)
            ->onOneServer();
    }

    private function scheduleUpdates($schedule): void
    {
        $updateCheckFrequency = $this->settings->update_check_frequency;
        $schedule->job(new CheckForUpdatesJob)
            ->cron($updateCheckFrequency)
            ->timezone($this->settings->instance_timezone)
            ->onOneServer();

        if ($this->settings->is_auto_update_enabled) {
            $autoUpdateFrequency = $this->settings->auto_update_frequency;
            $schedule->job(new UpdateCoolifyJob)
                ->cron($autoUpdateFrequency)
                ->timezone($this->settings->instance_timezone)
                ->onOneServer();
        }
    }

    private function checkResources($schedule): void
    {
        if (isCloud()) {
            $servers = $this->allServers->whereNotNull('team.subscription')->where('team.subscription.stripe_trial_already_ended', false);
            $own = Team::find(0)->servers;
            $servers = $servers->merge($own);
        } else {
            $servers = $this->allServers;
        }
        // $schedule->job(new ResourcesCheck)->everyMinute()->onOneServer();

        foreach ($servers as $server) {
            $serverTimezone = $server->settings->server_timezone;

            // Sentinel check
            $lastSentinelUpdate = $server->sentinel_updated_at;
            if (Carbon::parse($lastSentinelUpdate)->isBefore(now()->subSeconds($server->waitBeforeDoingSshCheck()))) {
                // Check container status every minute if Sentinel does not activated
                $schedule->job(new ServerCheckJob($server))->everyMinute()->onOneServer();

                // Check storage usage every 10 minutes if Sentinel does not activated
                $schedule->job(new ServerStorageCheckJob($server))->everyTenMinutes()->onOneServer();
            }
            if ($server->settings->force_docker_cleanup) {
                $schedule->job(new DockerCleanupJob($server))->cron($server->settings->docker_cleanup_frequency)->timezone($serverTimezone)->onOneServer();
            } else {
                $schedule->job(new DockerCleanupJob($server))->everyTenMinutes()->timezone($serverTimezone)->onOneServer();
            }

            // Cleanup multiplexed connections every hour
            $schedule->job(new ServerCleanupMux($server))->hourly()->onOneServer();

            // Temporary solution until we have better memory management for Sentinel
            if ($server->isSentinelEnabled()) {
                $schedule->job(function () use ($server) {
                    $server->restartContainer('coolify-sentinel');
                })->daily()->onOneServer();
            }
        }
    }

    private function checkScheduledBackups($schedule): void
    {
        $scheduled_backups = ScheduledDatabaseBackup::all();
        if ($scheduled_backups->isEmpty()) {
            return;
        }
        foreach ($scheduled_backups as $scheduled_backup) {
            if (! $scheduled_backup->enabled) {
                continue;
            }
            if (is_null(data_get($scheduled_backup, 'database'))) {
                $scheduled_backup->delete();

                continue;
            }

            $server = $scheduled_backup->server();

            if (! $server) {
                continue;
            }
            $serverTimezone = $server->settings->server_timezone;

            if (isset(VALID_CRON_STRINGS[$scheduled_backup->frequency])) {
                $scheduled_backup->frequency = VALID_CRON_STRINGS[$scheduled_backup->frequency];
            }
            $schedule->job(new DatabaseBackupJob(
                backup: $scheduled_backup
            ))->cron($scheduled_backup->frequency)->timezone($serverTimezone)->onOneServer();
        }
    }

    private function checkScheduledTasks($schedule): void
    {
        $scheduled_tasks = ScheduledTask::all();
        if ($scheduled_tasks->isEmpty()) {
            return;
        }
        foreach ($scheduled_tasks as $scheduled_task) {
            if ($scheduled_task->enabled === false) {
                continue;
            }
            $service = $scheduled_task->service;
            $application = $scheduled_task->application;

            if (! $application && ! $service) {
                $scheduled_task->delete();

                continue;
            }
            if ($application) {
                if (str($application->status)->contains('running') === false) {
                    continue;
                }
            }
            if ($service) {
                if (str($service->status())->contains('running') === false) {
                    continue;
                }
            }

            $server = $scheduled_task->server();
            if (! $server) {
                continue;
            }
            $serverTimezone = $server->settings->server_timezone ?: config('app.timezone');

            if (isset(VALID_CRON_STRINGS[$scheduled_task->frequency])) {
                $scheduled_task->frequency = VALID_CRON_STRINGS[$scheduled_task->frequency];
            }
            $schedule->job(new ScheduledTaskJob(
                task: $scheduled_task
            ))->cron($scheduled_task->frequency)->timezone($serverTimezone)->onOneServer();
        }
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
