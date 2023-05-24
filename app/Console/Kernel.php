<?php

namespace App\Console;

use App\Jobs\InstanceAutoUpdate;
use App\Jobs\InstanceProxyCheckJob;
use App\Jobs\InstanceDockerCleanup;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('horizon:snapshot')->everyFiveMinutes();

        $schedule->job(new InstanceDockerCleanup)->everyFiveMinutes();
        $schedule->job(new InstanceAutoUpdate)->everyFifteenMinutes();
        $schedule->job(new InstanceProxyCheckJob)->everyMinute();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
