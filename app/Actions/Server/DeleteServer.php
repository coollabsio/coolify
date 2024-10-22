<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteServer
{
    use AsAction;

    public function handle(Server $server)
    {
        StopSentinel::run($server);
        $server->forceDelete();
    }
}
