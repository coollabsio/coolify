<?php

namespace App\Console\Commands;

use App\Models\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

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
        // https://github.com/OAI/OpenAPI-Specification/releases
        $process = Process::run([
            '/var/www/html/vendor/bin/openapi',
            'app',
            '-o',
            'openapi.yaml',
            '--version',
            '3.1.0',
        ]);
        $error = $process->errorOutput();
        $error = preg_replace('/^.*an object literal,.*$/m', '', $error);
        $error = preg_replace('/^\h*\v+/m', '', $error);
        echo $error;
        echo $process->output();
        // Convert YAML to JSON
        $yaml = file_get_contents('openapi.yaml');
        $json = json_encode(Yaml::parse($yaml), JSON_PRETTY_PRINT);
        file_put_contents('openapi.json', $json);
        echo "Converted OpenAPI YAML to JSON.\n";
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
