<?php

namespace App\Console;

use App\Jobs\InstanceAutoUpdateJob;
use App\Jobs\ProxyCheckJob;
use App\Jobs\DockerCleanupJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        if (isDev()) {
            $schedule->command('horizon:snapshot')->everyMinute();
            $schedule->job(new DockerCleanupJob)->everyOddHour();
            // $schedule->job(new InstanceAutoUpdateJob(true))->everyMinute();
        } else {
            $schedule->command('horizon:snapshot')->everyFiveMinutes();
            $schedule->job(new ProxyCheckJob)->everyFiveMinutes();
            $schedule->job(new DockerCleanupJob)->everyTenMinutes();
            $schedule->job(new InstanceAutoUpdateJob)->everyTenMinutes();
        }
    }
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}