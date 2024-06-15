<?php

namespace App\Services\Remote\Provider;

use App\Models\Server;
use App\Services\Remote\RemoteProcessManager;

class RemoteProcessProvider
{
    public function forServer(Server $server): RemoteProcessManager
    {
        return new RemoteProcessManager($server);
    }
}
