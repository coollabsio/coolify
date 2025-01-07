<?php

namespace App\Console;

use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\CheckForUpdatesJob;
use App\Jobs\CheckHelperImageJob;
use App\Jobs\CleanupInstanceStuffsJob;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\DockerCleanupJob;
use App\Jobs\PullTemplatesFromCDN;
use App\Jobs\ScheduledTaskJob;
use App\Jobs\ServerCheckJob;
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

    private Schedule $schedule;

    private InstanceSettings $instanceSettings;

    private string $updateCheckFrequency;

    private string $instanceTimezone;

    protected function schedule(Schedule $schedule): void
    {
        $this->schedule = $schedule;
        $this->allServers = Server::query()->where('ip', '!=', '1.2.3.4');

        $this->instanceSettings = instanceSettings();
        $this->updateCheckFrequency = $this->instanceSettings->update_check_frequency ?: '0 * * * *';

        $this->instanceTimezone = $this->instanceSettings->instance_timezone ?: config('app.timezone');

        if (validate_timezone($this->instanceTimezone) === false) {
            $this->instanceTimezone = config('app.timezone');
        }

        // $this->scheduleInstance->job(new CleanupStaleMultiplexedConnections)->hourly();

        if (isDev()) {
            // Instance Jobs
            $this->schedule->command('horizon:snapshot')->everyMinute();
            $this->schedule->job(new CleanupInstanceStuffsJob)->everyMinute()->onOneServer();
            $this->schedule->job(new CheckHelperImageJob)->everyTenMinutes()->onOneServer();

            // Server Jobs
            $this->checkResources();

            $this->checkScheduledBackups();
            $this->checkScheduledTasks();

            $this->schedule->command('uploads:clear')->everyTwoMinutes();

        } else {
            // Instance Jobs
            $this->schedule->command('horizon:snapshot')->everyFiveMinutes();
            $this->schedule->command('cleanup:unreachable-servers')->daily()->onOneServer();

            $this->schedule->job(new PullTemplatesFromCDN)->cron($this->updateCheckFrequency)->timezone($this->instanceTimezone)->onOneServer();

            $this->schedule->job(new CleanupInstanceStuffsJob)->everyTwoMinutes()->onOneServer();
            $this->scheduleUpdates();

            // Server Jobs
            $this->checkResources();

            $this->pullImages();

            $this->checkScheduledBackups();
            $this->checkScheduledTasks();

            $this->schedule->command('cleanup:database --yes')->daily();
            $this->schedule->command('uploads:clear')->everyTwoMinutes();
        }
    }

    private function pullImages(): void
    {
        $servers = $this->allServers->whereRelation('settings', 'is_usable', true)->whereRelation('settings', 'is_reachable', true)->get();
        foreach ($servers as $server) {
            if ($server->isSentinelEnabled()) {
                $this->schedule->job(function () use ($server) {
                    CheckAndStartSentinelJob::dispatch($server);
                })->cron($this->updateCheckFrequency)->timezone($this->instanceTimezone)->onOneServer();
            }
        }
        $this->schedule->job(new CheckHelperImageJob)
            ->cron($this->updateCheckFrequency)
            ->timezone($this->instanceTimezone)
            ->onOneServer();
    }

    private function scheduleUpdates(): void
    {
        $this->schedule->job(new CheckForUpdatesJob)
            ->cron($this->updateCheckFrequency)
            ->timezone($this->instanceTimezone)
            ->onOneServer();

        if ($this->instanceSettings->is_auto_update_enabled) {
            $autoUpdateFrequency = $this->instanceSettings->auto_update_frequency;
            $this->schedule->job(new UpdateCoolifyJob)
                ->cron($autoUpdateFrequency)
                ->timezone($this->instanceTimezone)
                ->onOneServer();
        }
    }

    private function checkResources(): void
    {
        if (isCloud()) {
            $servers = $this->allServers->whereHas('team.subscription')->get();
            $own = Team::query()->find(0)->servers;
            $servers = $servers->merge($own);
        } else {
            $servers = $this->allServers->get();
        }

        foreach ($servers as $server) {
            $serverTimezone = data_get($server->settings, 'server_timezone', $this->instanceTimezone);
            if (validate_timezone($serverTimezone) === false) {
                $serverTimezone = config('app.timezone');
            }

            // Sentinel check
            $lastSentinelUpdate = $server->sentinel_updated_at;
            if (Carbon::parse($lastSentinelUpdate)->isBefore(now()->subSeconds($server->waitBeforeDoingSshCheck()))) {
                // Check container status every minute if Sentinel does not activated
                if (isCloud()) {
                    $this->schedule->job(new ServerCheckJob($server))->timezone($serverTimezone)->everyFiveMinutes()->onOneServer();
                } else {
                    $this->schedule->job(new ServerCheckJob($server))->timezone($serverTimezone)->everyMinute()->onOneServer();
                }
                // $this->scheduleInstance->job(new \App\Jobs\ServerCheckNewJob($server))->everyFiveMinutes()->onOneServer();

                $this->schedule->job(new ServerStorageCheckJob($server))->cron($server->settings->server_disk_usage_check_frequency)->timezone($serverTimezone)->onOneServer();
            }

            $this->schedule->job(new DockerCleanupJob($server))->cron($server->settings->docker_cleanup_frequency)->timezone($serverTimezone)->onOneServer();

            // Cleanup multiplexed connections every hour
            // $this->scheduleInstance->job(new ServerCleanupMux($server))->hourly()->onOneServer();

            // Temporary solution until we have better memory management for Sentinel
            if ($server->isSentinelEnabled()) {
                $this->schedule->job(function () use ($server) {
                    $server->restartContainer('coolify-sentinel');
                })->daily()->onOneServer();
            }
        }
    }

    private function checkScheduledBackups(): void
    {
        $scheduled_backups = ScheduledDatabaseBackup::query()->where('enabled', true)->get();
        if ($scheduled_backups->isEmpty()) {
            return;
        }
        foreach ($scheduled_backups as $scheduled_backup) {
            if (is_null(data_get($scheduled_backup, 'database'))) {
                $scheduled_backup->delete();

                continue;
            }

            $server = $scheduled_backup->server();

            if (is_null($server)) {
                continue;
            }
            if (isset(VALID_CRON_STRINGS[$scheduled_backup->frequency])) {
                $scheduled_backup->frequency = VALID_CRON_STRINGS[$scheduled_backup->frequency];
            }
            $serverTimezone = data_get($server->settings, 'server_timezone', $this->instanceTimezone);
            $this->schedule->job(new DatabaseBackupJob(
                scheduledDatabaseBackup: $scheduled_backup
            ))->cron($scheduled_backup->frequency)->timezone($serverTimezone)->onOneServer();
        }
    }

    private function checkScheduledTasks(): void
    {
        $scheduled_tasks = ScheduledTask::query()->where('enabled', true)->get();
        if ($scheduled_tasks->isEmpty()) {
            return;
        }
        foreach ($scheduled_tasks as $scheduled_task) {
            $service = $scheduled_task->service;
            $application = $scheduled_task->application;

            if (! $application && ! $service) {
                $scheduled_task->delete();

                continue;
            }
            if ($application && str($application->status)->contains('running') === false) {
                continue;
            }
            if ($service && str($service->status)->contains('running') === false) {
                continue;
            }

            $server = $scheduled_task->server();
            if (! $server) {
                continue;
            }

            if (isset(VALID_CRON_STRINGS[$scheduled_task->frequency])) {
                $scheduled_task->frequency = VALID_CRON_STRINGS[$scheduled_task->frequency];
            }
            $serverTimezone = data_get($server->settings, 'server_timezone', $this->instanceTimezone);
            $this->schedule->job(new ScheduledTaskJob(
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
