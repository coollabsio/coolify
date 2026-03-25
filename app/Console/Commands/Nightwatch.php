<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Nightwatch extends Command
{
    protected $signature = 'start:nightwatch';

    protected $description = 'Start Nightwatch';

    public function handle(): void
    {
        if (config('constants.nightwatch.is_nightwatch_enabled')) {
            $this->info('Nightwatch is enabled on this server.');
            $this->call('nightwatch:agent');
        }

        exit(0);
    }
}
