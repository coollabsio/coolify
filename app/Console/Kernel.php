<?php

namespace App\Console;

use App\Jobs\AutoUpdateJob;
use App\Jobs\ContainerStatusJob;
use App\Jobs\DockerCleanupDanglingImagesJob;
use App\Jobs\ProxyCheckJob;
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

        $schedule->job(new DockerCleanupDanglingImagesJob)->everyFiveMinutes();
        $schedule->job(new AutoUpdateJob)->everyFifteenMinutes();
        $schedule->job(new ProxyCheckJob)->everyMinute();
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
