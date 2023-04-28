<?php

namespace App\Console;

use App\Jobs\ContainerStatusJob;
use App\Jobs\DockerCleanupDanglingImagesJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new ContainerStatusJob)->everyMinute();
        $schedule->job(new DockerCleanupDanglingImagesJob)->everyMinute();
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
