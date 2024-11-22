<?php

namespace App\Actions\Service;

use App\Models\Service;
use Lorisleiva\Actions\Concerns\AsAction;

class RestartService
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Service $service)
    {
        StopService::run($service);

        return StartService::run($service);
    }
}
