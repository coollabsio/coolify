<?php

namespace App\Services\Docker\Concerns;

use App\Models\Server;
use Closure;

trait ExecutesDockerCommands
{
    private function executeDocker(Server $server, array $command, ?Closure $executor = null): ?string
    {
        if ($executor) {
            return $executor($server, $command);
        }

        return instant_remote_process($command, $server, false);
    }
}
