<?php

namespace App\Console\Commands;

use App\Models\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

class Dev extends Command
{
    protected $signature = 'dev {--init} {--generate-openapi}';

    protected $description = 'Helper commands for development.';

    public function handle()
    {
        if ($this->option('init')) {
            $this->init();

            return;
        }
        if ($this->option('generate-openapi')) {
            $this->generateOpenApi();

            return;
        }

    }

    public function generateOpenApi()
    {
        // Generate OpenAPI documentation
        echo "Generating OpenAPI documentation.\n";
        $process = Process::run(['/var/www/html/vendor/bin/openapi', 'app', '-o', 'openapi.yaml']);
        $error = $process->errorOutput();
        $error = preg_replace('/^.*an object literal,.*$/m', '', $error);
        $error = preg_replace('/^\h*\v+/m', '', $error);
        echo $error;
        echo $process->output();
    }

    public function init()
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
