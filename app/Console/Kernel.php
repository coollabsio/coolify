<?php

namespace App\Console;

use App\Jobs\InstanceAutoUpdateJob;
use App\Jobs\InstanceProxyCheckJob;
use App\Jobs\InstanceDockerCleanupJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        if (config('app.env') === 'local') {
            $schedule->command('horizon:snapshot')->everyMinute();
            $schedule->job(new InstanceDockerCleanupJob)->everyMinute();
            $schedule->job(new InstanceAutoUpdateJob(true))->everyMinute();
        } else {
            $schedule->command('horizon:snapshot')->everyFiveMinutes();
            $schedule->job(new InstanceDockerCleanupJob)->everyFiveMinutes();
            $schedule->job(new InstanceAutoUpdateJob)->everyFifteenMinutes();
        }
    }
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
