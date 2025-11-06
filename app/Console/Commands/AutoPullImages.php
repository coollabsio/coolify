<?php

namespace App\Console\Commands;

use App\Actions\Service\StartService;
use App\Models\Service;
use Illuminate\Console\Command;

class AutoPullImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'services:auto-pull {schedule?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically pull latest images and restart services based on their schedule';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $schedule = $this->argument('schedule');

        $query = Service::where('auto_image_pull_enabled', true);

        if ($schedule) {
            $query->where('auto_image_pull_schedule', $schedule);
        }

        $services = $query->get();

        if ($services->isEmpty()) {
            $this->info('No services configured for auto pull.');

            return 0;
        }

        $this->info("Found {$services->count()} service(s) to update.");

        foreach ($services as $service) {
            try {
                $this->info("Processing service: {$service->name} (UUID: {$service->uuid})");

                // Update last check timestamp
                $service->last_image_pull_check = now();
                $service->save();

                // Check if service is running
                if (! str($service->status)->contains('running')) {
                    $this->warn("  Service is not running. Skipping...");

                    continue;
                }

                // Pull latest images and restart
                $this->info('  Pulling latest images and restarting...');
                StartService::run($service, pullLatestImages: true, stopBeforeStart: true);

                $this->info("  ✓ Successfully queued update for {$service->name}");
            } catch (\Exception $e) {
                $this->error("  ✗ Failed to update {$service->name}: {$e->getMessage()}");
            }
        }

        $this->info('Auto pull completed.');

        return 0;
    }
}
