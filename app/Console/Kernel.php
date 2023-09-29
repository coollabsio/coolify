<?php

namespace App\Console;

use App\Jobs\CheckResaleLicenseJob;
use App\Jobs\CleanupInstanceStuffsJob;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\DockerCleanupJob;
use App\Jobs\InstanceAutoUpdateJob;
use App\Jobs\ContainerStatusJob;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        if (isDev()) {
            // $schedule->job(new ContainerStatusJob(Server::find(0)))->everyTenMinutes()->onOneServer();
            // $schedule->command('horizon:snapshot')->everyMinute();
            $schedule->job(new CleanupInstanceStuffsJob)->everyMinute()->onOneServer();
            // $schedule->job(new CheckResaleLicenseJob)->hourly();
            // $schedule->job(new DockerCleanupJob)->everyOddHour();
            // $this->instance_auto_update($schedule);
            // $this->check_scheduled_backups($schedule);
            $this->check_resources($schedule);
            $this->cleanup_servers($schedule);
        } else {
            $schedule->command('horizon:snapshot')->everyFiveMinutes();
            $schedule->job(new CleanupInstanceStuffsJob)->everyTwoMinutes()->onOneServer();
            $schedule->job(new CheckResaleLicenseJob)->hourly()->onOneServer();
            // $schedule->job(new DockerCleanupJob)->everyTenMinutes()->onOneServer();
            $this->instance_auto_update($schedule);
            $this->check_scheduled_backups($schedule);
            $this->check_resources($schedule);
            $this->cleanup_servers($schedule);
        }
    }
    private function cleanup_servers($schedule)
    {
        $servers = Server::all()->where('settings.is_usable', true)->where('settings.is_reachable', true);
        foreach ($servers as $server) {
            $schedule->job(new DockerCleanupJob($server))->everyTenMinutes()->onOneServer();
        }
    }
    private function check_resources($schedule)
    {
        $servers = Server::all()->where('settings.is_usable', true)->where('settings.is_reachable', true);
        foreach ($servers as $server) {
            $schedule->job(new ContainerStatusJob($server))->everyMinute()->onOneServer();
        }
    }
    private function instance_auto_update($schedule)
    {
        if (isDev()) {
            return;
        }
        $settings = InstanceSettings::get();
        if ($settings->is_auto_update_enabled) {
            $schedule->job(new InstanceAutoUpdateJob)->everyTenMinutes()->onOneServer();
        }
    }
    private function check_scheduled_backups($schedule)
    {
        ray('check_scheduled_backups');
        $scheduled_backups = ScheduledDatabaseBackup::all();
        if ($scheduled_backups->isEmpty()) {
            ray('no scheduled backups');
            return;
        }
        foreach ($scheduled_backups as $scheduled_backup) {
            if (!$scheduled_backup->enabled) {
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

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
