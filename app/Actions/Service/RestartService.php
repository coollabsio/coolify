<?php

namespace App\Actions\Service;

use App\Models\Service;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;

class RestartService
{
    use AsAction;

    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue(deployment_queue());
    }

    public function handle(Service $service, bool $pullLatestImages)
    {
        return StartService::run(
            service: $service,
            pullLatestImages: $pullLatestImages,
            stopBeforeStart: true,
        );
    }
}
