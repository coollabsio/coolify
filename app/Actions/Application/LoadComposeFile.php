<?php

namespace App\Actions\Application;

use App\Models\Application;
use Lorisleiva\Actions\Concerns\AsAction;

class LoadComposeFile
{
    use AsAction;

    public function handle(Application $application)
    {
        $application->loadComposeFile();
    }
}
