<?php

namespace App\Console\Commands;

use App\Jobs\ScheduledJobManager;
use Illuminate\Console\Command;

class DispatchScheduledJobs extends Command
{
    protected $signature = 'scheduled:dispatch
        {--type= : Only one schedule type: backups, tasks, volume-backups, or docker-cleanups. Empty runs all types.}';

    protected $description = 'Dispatch the due scheduled backups, tasks, volume backups, and Docker cleanups';

    public function handle(): int
    {
        $type = $this->option('type') ?: null;

        if ($type !== null && ! array_key_exists($type, ScheduledJobManager::TYPES)) {
            $this->error("Unknown type [{$type}]. Use one of: ".implode(', ', array_keys(ScheduledJobManager::TYPES)).'.');

            return self::INVALID;
        }

        ScheduledJobManager::dispatchSync($type);

        return self::SUCCESS;
    }
}
