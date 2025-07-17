<?php

namespace App\Console\Commands;

use App\Models\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class Dev extends Command
{
    protected $signature = 'dev {--init}';

    protected $description = 'Helper commands for development.';

    public function handle()
    {
        if ($this->option('init')) {
            $this->init();

            return;
        }
    }

    public function init()
    {
        // Generate APP_KEY if not exists

        if (empty(config('app.key'))) {
            echo "Generating APP_KEY.\n";
            Artisan::call('key:generate');
        }

        // Generate STORAGE link if not exists
        if (! file_exists(public_path('storage'))) {
            echo "Generating STORAGE link.\n";
            Artisan::call('storage:link');
        }

        // Seed database if it's empty
        $settings = InstanceSettings::find(0);
        if (! $settings) {
            echo "Initializing instance, seeding database.\n";
            Artisan::call('migrate --seed');
        } else {
            echo "Instance already initialized.\n";
        }
    }
}
