<?php

namespace App\Console;

use App\Jobs\CheckForUpdatesJob;
use App\Jobs\CheckHelperImageJob;
use App\Jobs\CheckTraefikVersionJob;
use App\Jobs\CleanupInstanceStuffsJob;
use App\Jobs\CleanupOrphanedPreviewContainersJob;
use App\Jobs\PullChangelog;
use App\Jobs\PullTemplatesFromCDN;
use App\Jobs\RegenerateSslCertJob;
use App\Jobs\ScheduledJobManager;
use App\Jobs\ServerManagerJob;
use App\Jobs\UpdateCoolifyJob;
use App\Models\InstanceSettings;
use Illuminate\Console\Scheduling\Schedule;

class ScheduleConfiguration
{
    private InstanceSettings $settings;

    private string $updateCheckFrequency;

    private string $instanceTimezone;

    public function __construct(private Schedule $schedule) {}

    public function configure(): void
    {
        try {
            $this->settings = instanceSettings();
        } catch (\Throwable) {
            // Database not migrated or seeded yet — skip schedule registration
            return;
        }

        $this->updateCheckFrequency = $this->settings->update_check_frequency ?: '0 * * * *';

        $this->instanceTimezone = $this->settings->instance_timezone ?: config('app.timezone');

        if (validate_timezone($this->instanceTimezone) === false) {
            $this->instanceTimezone = config('app.timezone');
        }

        // $this->schedule->job(new CleanupStaleMultiplexedConnections)->hourly();
        $this->schedule->command('cleanup:redis --clear-locks')->daily();

        if (isDev()) {
            $this->configureDev();
        } else {
            $this->configureProduction();
        }
    }

    private function configureDev(): void
    {
        // Instance Jobs
        $this->schedule->command('horizon:snapshot')->everyMinute();
        $this->schedule->job(new CleanupInstanceStuffsJob)->everyMinute()->onOneServer();
        $this->schedule->job(new CheckHelperImageJob)->everyTenMinutes()->onOneServer();

        // Server Jobs
        $this->schedule->job(new ServerManagerJob)->everyMinute()->onOneServer();

        // Scheduled Jobs (Backups & Tasks)
        $this->schedule->job(new ScheduledJobManager)->everyMinute()->onOneServer();

        $this->schedule->command('uploads:clear')->everyTwoMinutes();
    }

    private function configureProduction(): void
    {
        // Instance Jobs
        $this->schedule->command('horizon:snapshot')->everyFiveMinutes();
        $this->schedule->command('cleanup:unreachable-servers')->daily()->onOneServer();

        $this->schedule->job(new PullTemplatesFromCDN)->cron($this->updateCheckFrequency)->timezone($this->instanceTimezone)->onOneServer();
        $this->schedule->job(new PullChangelog)->cron($this->updateCheckFrequency)->timezone($this->instanceTimezone)->onOneServer();

        $this->schedule->job(new CleanupInstanceStuffsJob)->everyTwoMinutes()->onOneServer();
        $this->scheduleUpdates();

        // Server Jobs
        $this->schedule->job(new ServerManagerJob)->everyMinute()->onOneServer();

        $this->pullImages();

        // Scheduled Jobs (Backups & Tasks)
        $this->schedule->job(new ScheduledJobManager)->everyMinute()->onOneServer();

        $this->schedule->job(new RegenerateSslCertJob)->twiceDaily();

        $this->schedule->job(new CheckTraefikVersionJob)->weekly()->sundays()->at('00:00')->timezone($this->instanceTimezone)->onOneServer();

        $this->schedule->command('cleanup:database --yes')->daily();
        $this->schedule->command('uploads:clear')->everyTwoMinutes();

        // Cleanup orphaned PR preview containers daily
        $this->schedule->job(new CleanupOrphanedPreviewContainersJob)->daily()->onOneServer();
    }

    private function pullImages(): void
    {
        $this->schedule->job(new CheckHelperImageJob)
            ->cron($this->updateCheckFrequency)
            ->timezone($this->instanceTimezone)
            ->onOneServer();
    }

    private function scheduleUpdates(): void
    {
        $this->schedule->job(new CheckForUpdatesJob)
            ->cron($this->updateCheckFrequency)
            ->timezone($this->instanceTimezone)
            ->onOneServer();

        if ($this->settings->is_auto_update_enabled) {
            $autoUpdateFrequency = $this->settings->auto_update_frequency;
            $this->schedule->job(new UpdateCoolifyJob)
                ->cron($autoUpdateFrequency)
                ->timezone($this->instanceTimezone)
                ->onOneServer();
        }
    }
}
