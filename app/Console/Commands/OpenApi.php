<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class OpenApi extends Command
{
    protected $signature = 'openapi';

    protected $description = 'Generate OpenApi file.';

    public function handle()
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
}
