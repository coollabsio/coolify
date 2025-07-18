<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class RestartContainer
{
    use AsAction;

    public function handle(Server $server, string $containerName)
    {
        $server->restartContainer($containerName);
    }
}
