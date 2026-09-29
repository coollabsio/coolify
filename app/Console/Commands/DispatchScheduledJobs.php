<?php

namespace App\Console\Commands;

use App\Jobs\ScheduledJobManager;
use Illuminate\Console\Command;

class DispatchScheduledJobs extends Command
{
    protected $signature = 'scheduled:dispatch';

    protected $description = 'Dispatch the due scheduled backups, tasks, volume backups, and Docker cleanups';

    public function handle(ScheduledJobManager $manager): int
    {
        $manager->handle();

        return self::SUCCESS;
    }
}
