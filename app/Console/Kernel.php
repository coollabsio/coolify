<?php

namespace App\Console;

use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\CheckForUpdatesJob;
use App\Jobs\CheckHelperImageJob;
use App\Jobs\CheckTraefikVersionJob;
use App\Jobs\CleanupInstanceStuffsJob;
use App\Jobs\PullChangelog;
use App\Jobs\PullTemplatesFromCDN;
use App\Jobs\RegenerateSslCertJob;
use App\Jobs\ScheduledJobManager;
use App\Jobs\ServerManagerJob;
use App\Jobs\UpdateCoolifyJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

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
        $this->scheduleInstance->command('cleanup:redis')->weekly();

        if (isDev()) {
            // Instance Jobs
            $this->scheduleInstance->command('horizon:snapshot')->everyMinute();
            $this->scheduleInstance->job(new CleanupInstanceStuffsJob)->everyMinute()->onOneServer();
            $this->scheduleInstance->job(new CheckHelperImageJob)->everyTenMinutes()->onOneServer();

            // Server Jobs
            $this->scheduleInstance->job(new ServerManagerJob)->everyMinute()->onOneServer();

            // Scheduled Jobs (Backups & Tasks)
            $this->scheduleInstance->job(new ScheduledJobManager)->everyMinute()->onOneServer();

            $this->scheduleInstance->command('uploads:clear')->everyTwoMinutes();

        } else {
            // Instance Jobs
            $this->scheduleInstance->command('horizon:snapshot')->everyFiveMinutes();
            $this->scheduleInstance->command('cleanup:unreachable-servers')->daily()->onOneServer();

            $this->scheduleInstance->job(new PullTemplatesFromCDN)->cron($this->updateCheckFrequency)->timezone($this->instanceTimezone)->onOneServer();
            $this->scheduleInstance->job(new PullChangelog)->cron($this->updateCheckFrequency)->timezone($this->instanceTimezone)->onOneServer();

            $this->scheduleInstance->job(new CleanupInstanceStuffsJob)->everyTwoMinutes()->onOneServer();
            $this->scheduleUpdates();

            // Server Jobs
            $this->scheduleInstance->job(new ServerManagerJob)->everyMinute()->onOneServer();

            $this->pullImages();

            // Scheduled Jobs (Backups & Tasks)
            $this->scheduleInstance->job(new ScheduledJobManager)->everyMinute()->onOneServer();

            $this->scheduleInstance->job(new RegenerateSslCertJob)->twiceDaily();

            $this->scheduleInstance->job(new CheckTraefikVersionJob)->weekly()->sundays()->at('00:00')->timezone($this->instanceTimezone)->onOneServer();

            $this->scheduleInstance->command('cleanup:database --yes')->daily();
            $this->scheduleInstance->command('uploads:clear')->everyTwoMinutes();
        }
    }

    private function pullImages(): void
    {
        if (isCloud()) {
            $servers = $this->allServers->whereRelation('team.subscription', 'stripe_invoice_paid', true)->whereRelation('settings', 'is_usable', true)->whereRelation('settings', 'is_reachable', true)->get();
            $own = Team::find(0)->servers;
            $servers = $servers->merge($own);
        } else {
            $servers = $this->allServers->whereRelation('settings', 'is_usable', true)->whereRelation('settings', 'is_reachable', true)->get();
        }
        foreach ($servers as $server) {
            try {
                if ($server->isSentinelEnabled()) {
                    $this->scheduleInstance->job(function () use ($server) {
                        CheckAndStartSentinelJob::dispatch($server);
                    })->cron($this->updateCheckFrequency)->timezone($this->instanceTimezone)->onOneServer();
                }
            } catch (\Exception $e) {
                Log::error('Error pulling images: '.$e->getMessage());
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

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
