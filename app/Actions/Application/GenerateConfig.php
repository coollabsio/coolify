<?php

namespace App\Actions\Application;

use App\Models\Application;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateConfig
{
    use AsAction;

    public function handle(Application $application, bool $is_json = false)
    {
        ray()->clearAll();

        return $application->generateConfig(is_json: $is_json);
    }
}
