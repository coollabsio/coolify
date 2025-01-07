<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class StopLogDrain
{
    use AsAction;

    public function handle(Server $server)
    {
        try {
            return instant_remote_process(['docker rm -f coolify-log-drain'], $server, false);
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }
}
