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
        $process = Process::run([
            './vendor/bin/openapi',
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

        $yaml = file_get_contents('openapi.yaml');
        $json = json_encode(Yaml::parse($yaml), JSON_PRETTY_PRINT);
        file_put_contents('openapi.json', $json);
        echo "Converted OpenAPI YAML to JSON.\n";
    }
}
