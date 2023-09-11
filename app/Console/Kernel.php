<?php

namespace App\Console;

use App\Enums\ProxyTypes;
use App\Jobs\ApplicationContainerStatusJob;
use App\Jobs\CheckResaleLicenseJob;
use App\Jobs\CleanupInstanceStuffsJob;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\DatabaseContainerStatusJob;
use App\Jobs\DockerCleanupJob;
use App\Jobs\InstanceAutoUpdateJob;
use App\Jobs\ProxyContainerStatusJob;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        if (isDev()) {
            $schedule->command('horizon:snapshot')->everyMinute();
            $schedule->job(new CleanupInstanceStuffsJob)->everyMinute();
            // $schedule->job(new CheckResaleLicenseJob)->hourly();
            $schedule->job(new DockerCleanupJob)->everyOddHour();
        } else {
            $schedule->command('horizon:snapshot')->everyFiveMinutes();
            $schedule->job(new CleanupInstanceStuffsJob)->everyTenMinutes()->onOneServer();
            $schedule->job(new CheckResaleLicenseJob)->hourly()->onOneServer();
            $schedule->job(new DockerCleanupJob)->everyTenMinutes()->onOneServer();
        }
        $this->instance_auto_update($schedule);
        $this->check_scheduled_backups($schedule);
        $this->check_resources($schedule);
        $this->check_proxies($schedule);
    }
    private function check_proxies($schedule)
    {
        $servers = Server::all()->where('settings.is_usable', true)->where('settings.is_reachable', true)->whereNotNull('proxy.type')->where('proxy.type', '!=', ProxyTypes::NONE->value);
        foreach ($servers as $server) {
            $schedule->job(new ProxyContainerStatusJob($server))->everyMinute()->onOneServer();
        }
    }
    private function check_resources($schedule)
    {
        $applications = Application::all();
        foreach ($applications as $application) {
            $schedule->job(new ApplicationContainerStatusJob($application))->everyMinute()->onOneServer();
        }

        $postgresqls = StandalonePostgresql::all();
        foreach ($postgresqls as $postgresql) {
            $schedule->job(new DatabaseContainerStatusJob($postgresql))->everyMinute()->onOneServer();
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
