<?php

namespace App\Console;

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

    private Schedule $scheduleInstance;

    private InstanceSettings $settings;

    private string $updateCheckFrequency;

    private string $instanceTimezone;

    protected function schedule(Schedule $schedule): void
    {
        $this->scheduleInstance = $schedule;
        $this->allServers = Server::where('ip', '!=', '1.2.3.4');

        $this->settings = instanceSettings();
        $this->updateCheckFrequency = $this->settings->update_check_frequency ?: '0 * * * *';

        $this->instanceTimezone = $this->settings->instance_timezone ?: config('app.timezone');

        if (validate_timezone($this->instanceTimezone) === false) {
            $this->instanceTimezone = config('app.timezone');
        }

        // $this->scheduleInstance->job(new CleanupStaleMultiplexedConnections)->hourly();

        if (isDev()) {
            // Instance Jobs
            $this->scheduleInstance->command('horizon:snapshot')->everyMinute();
            $this->scheduleInstance->job(new CleanupInstanceStuffsJob)->everyMinute()->onOneServer();
            $this->scheduleInstance->job(new CheckHelperImageJob)->everyTenMinutes()->onOneServer();

            // Server Jobs
            $this->checkResources();

            $this->checkScheduledBackups();
            $this->checkScheduledTasks();

            $this->scheduleInstance->command('uploads:clear')->everyTwoMinutes();

        } else {
            // Instance Jobs
            $this->scheduleInstance->command('horizon:snapshot')->everyFiveMinutes();
            $this->scheduleInstance->command('cleanup:unreachable-servers')->daily()->onOneServer();

            $this->scheduleInstance->job(new PullTemplatesFromCDN)->cron($this->updateCheckFrequency)->timezone($this->instanceTimezone)->onOneServer();

            $this->scheduleInstance->job(new CleanupInstanceStuffsJob)->everyTwoMinutes()->onOneServer();
            $this->scheduleUpdates();

            // Server Jobs
            $this->checkResources();

            $this->pullImages();

            $this->checkScheduledBackups();
            $this->checkScheduledTasks();

            $this->scheduleInstance->command('cleanup:database --yes')->daily();
            $this->scheduleInstance->command('uploads:clear')->everyTwoMinutes();
        }
    }

    private function pullImages(): void
    {
        $servers = $this->allServers->whereRelation('settings', 'is_usable', true)->whereRelation('settings', 'is_reachable', true)->get();
        foreach ($servers as $server) {
            if ($server->isSentinelEnabled()) {
                $this->scheduleInstance->job(function () use ($server) {
                    CheckAndStartSentinelJob::dispatch($server);
                })->cron($this->updateCheckFrequency)->timezone($this->instanceTimezone)->onOneServer();
            }
        }
        $this->scheduleInstance->job(new CheckHelperImageJob)
            ->cron($this->updateCheckFrequency)
            ->timezone($this->instanceTimezone)
            ->onOneServer();
    }

    private function scheduleUpdates(): void
    {
        $this->scheduleInstance->job(new CheckForUpdatesJob)
            ->cron($this->updateCheckFrequency)
            ->timezone($this->instanceTimezone)
            ->onOneServer();

        if ($this->settings->is_auto_update_enabled) {
            $autoUpdateFrequency = $this->settings->auto_update_frequency;
            $this->scheduleInstance->job(new UpdateCoolifyJob)
                ->cron($autoUpdateFrequency)
                ->timezone($this->instanceTimezone)
                ->onOneServer();
        }
    }

    private function checkResources(): void
    {
        if (isCloud()) {
            $servers = $this->allServers->whereHas('team.subscription')->get();
            $own = Team::find(0)->servers;
            $servers = $servers->merge($own);
        } else {
            $servers = $this->allServers->get();
        }

        foreach ($servers as $server) {
            $serverTimezone = data_get($server->settings, 'server_timezone', $this->instanceTimezone);

            // Sentinel check
            $lastSentinelUpdate = $server->sentinel_updated_at;
            if (Carbon::parse($lastSentinelUpdate)->isBefore(now()->subSeconds($server->waitBeforeDoingSshCheck()))) {
                // Check container status every minute if Sentinel does not activated
                if (validate_timezone($serverTimezone) === false) {
                    $serverTimezone = config('app.timezone');
                }
                if (isCloud()) {
                    $this->scheduleInstance->job(new ServerCheckJob($server))->timezone($serverTimezone)->everyFiveMinutes()->onOneServer();
                } else {
                    $this->scheduleInstance->job(new ServerCheckJob($server))->timezone($serverTimezone)->everyMinute()->onOneServer();
                }
                // $this->scheduleInstance->job(new \App\Jobs\ServerCheckNewJob($server))->everyFiveMinutes()->onOneServer();

                // Check storage usage every 10 minutes if Sentinel does not activated
                $this->scheduleInstance->job(new ServerStorageCheckJob($server))->everyTenMinutes()->onOneServer();
            }
            if ($server->settings->force_docker_cleanup) {
                $this->scheduleInstance->job(new DockerCleanupJob($server))->cron($server->settings->docker_cleanup_frequency)->timezone($serverTimezone)->onOneServer();
            } else {
                $this->scheduleInstance->job(new DockerCleanupJob($server))->everyTenMinutes()->timezone($serverTimezone)->onOneServer();
            }

            // Cleanup multiplexed connections every hour
            // $this->scheduleInstance->job(new ServerCleanupMux($server))->hourly()->onOneServer();

            // Temporary solution until we have better memory management for Sentinel
            if ($server->isSentinelEnabled()) {
                $this->scheduleInstance->job(function () use ($server) {
                    $server->restartContainer('coolify-sentinel');
                })->daily()->onOneServer();
            }
        }
    }

    private function checkScheduledBackups(): void
    {
        $scheduled_backups = ScheduledDatabaseBackup::where('enabled', true)->get();
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
            $this->scheduleInstance->job(new DatabaseBackupJob(
                backup: $scheduled_backup
            ))->cron($scheduled_backup->frequency)->timezone($this->instanceTimezone)->onOneServer();
        }
    }

    private function checkScheduledTasks(): void
    {
        $scheduled_tasks = ScheduledTask::where('enabled', true)->get();
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
            if ($application) {
                if (str($application->status)->contains('running') === false) {
                    continue;
                }
            }
            if ($service) {
                if (str($service->status)->contains('running') === false) {
                    continue;
                }
            }

            $server = $scheduled_task->server();
            if (! $server) {
                continue;
            }

            if (isset(VALID_CRON_STRINGS[$scheduled_task->frequency])) {
                $scheduled_task->frequency = VALID_CRON_STRINGS[$scheduled_task->frequency];
            }
            $this->scheduleInstance->job(new ScheduledTaskJob(
                task: $scheduled_task
            ))->cron($scheduled_task->frequency)->timezone($this->instanceTimezone)->onOneServer();
        }
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
