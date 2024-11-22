<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Scheduler extends Command
{
    protected $signature = 'start:scheduler';

    protected $description = 'Start Scheduler';

    public function handle()
    {
        if (config('coolify.is_scheduler_enabled')) {
            $this->info('Scheduler is enabled. Starting.');
            $this->call('schedule:work');
            exit(0);
        } else {
            exit(0);
        }
    }
}
