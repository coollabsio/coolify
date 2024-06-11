<?php

namespace App\Console\Commands;

use App\Models\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

class Dev extends Command
{
    protected $signature = 'dev:init';

    protected $description = 'Init the app in dev mode';

    public function handle()
    {
        // Generate APP_KEY if not exists
        if (empty(env('APP_KEY'))) {
            echo "Generating APP_KEY.\n";
            Artisan::call('key:generate');
        }
        // Seed database if it's empty
        $settings = InstanceSettings::find(0);
        if (! $settings) {
            echo "Initializing instance, seeding database.\n";
            Artisan::call('migrate --seed');
        } else {
            echo "Instance already initialized.\n";
        }
        // Set permissions
        Process::run(['chmod', '-R', 'o+rwx', '.']);
    }
}
