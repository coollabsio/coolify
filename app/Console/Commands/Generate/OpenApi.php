<?php

namespace App\Console\Commands\Generate;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

class OpenApi extends Command
{
    protected $signature = 'generate:openapi';

    protected $description = 'Generate OpenApi file.';

    public function handle()
    {
        // Generate OpenAPI documentation
        echo "Generating OpenAPI documentation.\n";
        // https://github.com/OAI/OpenAPI-Specification/releases
        // swagger-php 6 leaves PHP warnings to PHP itself; with Xdebug each one carries a stack trace and exhausts memory.
        $process = Process::env(['XDEBUG_MODE' => 'off'])->run([
            './vendor/bin/openapi',
            'app',
            // Server transfer endpoints are development-only (isDev()), so they stay out of the public docs.
            '--exclude',
            'Http/Controllers/Api/ServerTransferController.php',
            '-o',
            'openapi.yaml',
            '--version',
            '3.1.0',
        ]);
        foreach ([$process->errorOutput(), $process->output()] as $output) {
            $output = preg_replace('/^.*an object literal,.*$/m', '', $output);
            $output = preg_replace('/^\h*\v+/m', '', $output);
            echo $output;
        }

        $yaml = file_get_contents('openapi.yaml');

        $json = json_encode(Yaml::parse($yaml), JSON_PRETTY_PRINT)."\n";
        file_put_contents('openapi.json', $json);
        echo "Converted OpenAPI YAML to JSON.\n";
    }
}
