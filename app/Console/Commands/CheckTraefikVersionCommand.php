<?php

namespace App\Console\Commands;

use App\Jobs\CheckTraefikVersionJob;
use Illuminate\Console\Command;

class CheckTraefikVersionCommand extends Command
{
    protected $signature = 'traefik:check-version';

    protected $description = 'Check Traefik proxy versions on all servers and send notifications for outdated versions';

    public function handle(): int
    {
        $this->info('Checking Traefik versions on all servers...');

        try {
            CheckTraefikVersionJob::dispatch();
            $this->info('Traefik version check job dispatched successfully.');
            $this->info('Notifications will be sent to teams with outdated Traefik versions.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to dispatch Traefik version check job: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
