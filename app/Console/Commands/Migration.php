<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Migration extends Command
{
    protected $signature = 'start:migration';

    protected $description = 'Start Migration';

    public function handle()
    {
        if (config('constants.migration.is_migration_enabled')) {
            $this->info('Migration is enabled on this server.');
            $this->call('migrate', ['--force' => true, '--isolated' => true]);
            exit(0);
        } else {
            $this->info('Migration is disabled on this server.');
            exit(0);
        }
    }
}
