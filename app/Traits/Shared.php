<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

trait Shared
{
    public function get_workdir(string $type, string $resource_id, string $deployment_id)
    {
        $workdir = "/tmp/coolify/$type/{$resource_id}/{$deployment_id}/";
        return $workdir;
    }
}
