<?php

namespace App\Console;

use App\Jobs\CheckForUpdatesJob;
use App\Jobs\CleanupInstanceStuffsJob;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\DockerCleanupJob;
use App\Jobs\PullCoolifyImageJob;
use App\Jobs\PullHelperImageJob;
use App\Jobs\PullSentinelImageJob;
use App\Jobs\PullTemplatesFromCDN;
use App\Jobs\ScheduledTaskJob;
use App\Jobs\ServerCheckJob;
use App\Jobs\UpdateCoolifyJob;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    private $all_servers;

    protected function schedule(Schedule $schedule): void
    {
        $this->all_servers = Server::all();
        $settings = InstanceSettings::get();

        if (isDev()) {
            // Instance Jobs
            $schedule->command('horizon:snapshot')->everyMinute();
            $schedule->job(new CleanupInstanceStuffsJob)->everyMinute()->onOneServer();

            // Server Jobs
            $this->check_scheduled_backups($schedule);
            $this->check_resources($schedule);
            $this->check_scheduled_tasks($schedule);
            $schedule->command('uploads:clear')->everyTwoMinutes();
        } else {
            // Instance Jobs
            $schedule->command('horizon:snapshot')->everyFiveMinutes();
            $schedule->command('cleanup:unreachable-servers')->daily();
            $schedule->job(new PullCoolifyImageJob)->cron($settings->update_check_frequency)->onOneServer();
            $schedule->job(new PullTemplatesFromCDN)->cron($settings->update_check_frequency)->onOneServer();
            $schedule->job(new CleanupInstanceStuffsJob)->everyTwoMinutes()->onOneServer();
            $this->schedule_updates($schedule);

            // Server Jobs
            $this->check_scheduled_backups($schedule);
            $this->check_resources($schedule);
            $this->pull_images($schedule);
            $this->check_scheduled_tasks($schedule);

            $schedule->command('cleanup:database --yes')->daily();
            $schedule->command('uploads:clear')->everyTwoMinutes();
        }
    }

    private function pull_images($schedule)
    {
        $settings = InstanceSettings::get();
        $servers = $this->all_servers->where('settings.is_usable', true)->where('settings.is_reachable', true)->where('ip', '!=', '1.2.3.4');
        foreach ($servers as $server) {
            if ($server->isSentinelEnabled()) {
                $schedule->job(new PullSentinelImageJob($server))->cron($settings->update_check_frequency)->onOneServer();
            }
            $schedule->job(new PullHelperImageJob($server))->cron($settings->update_check_frequency)->onOneServer();
        }
    }

    private function schedule_updates($schedule)
    {
        $settings = InstanceSettings::get();

        $updateCheckFrequency = $settings->update_check_frequency;
        $schedule->job(new CheckForUpdatesJob)->cron($updateCheckFrequency)->onOneServer();

        if ($settings->is_auto_update_enabled) {
            $autoUpdateFrequency = $settings->auto_update_frequency;
            $schedule->job(new UpdateCoolifyJob)->cron($autoUpdateFrequency)->onOneServer();
        }
    }

    private function check_resources($schedule)
    {
        if (isCloud()) {
            $servers = $this->all_servers->whereNotNull('team.subscription')->where('team.subscription.stripe_trial_already_ended', false)->where('ip', '!=', '1.2.3.4');
            $own = Team::find(0)->servers;
            $servers = $servers->merge($own);
        } else {
            $servers = $this->all_servers->where('ip', '!=', '1.2.3.4');
        }
        foreach ($servers as $server) {
            $schedule->job(new ServerCheckJob($server))->everyMinute()->onOneServer();
            $schedule->job(new DockerCleanupJob($server))->everyTenMinutes()->onOneServer();
        }
    }

    private function check_scheduled_backups($schedule)
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
                ray('database not found');
                $scheduled_backup->delete();

                continue;
            }

            if (isset(VALID_CRON_STRINGS[$scheduled_backup->frequency])) {
                $scheduled_backup->frequency = VALID_CRON_STRINGS[$scheduled_backup->frequency];
            }
            $schedule->job(new DatabaseBackupJob(
                backup: $scheduled_backup
            ))->cron($scheduled_backup->frequency)->onOneServer();
        }
    }

    private function check_scheduled_tasks($schedule)
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
                ray('application/service attached to scheduled task does not exist');
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
            if (isset(VALID_CRON_STRINGS[$scheduled_task->frequency])) {
                $scheduled_task->frequency = VALID_CRON_STRINGS[$scheduled_task->frequency];
            }
            $schedule->job(new ScheduledTaskJob(
                task: $scheduled_task
            ))->cron($scheduled_task->frequency)->onOneServer();
        }
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
