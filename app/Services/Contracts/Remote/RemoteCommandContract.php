<?php

namespace App\Services\Contracts\Remote;

use App\Models\Server;
use Illuminate\Support\Collection;

interface RemoteCommandContract
{
    public function executeRemoteCommand(Server $server, array $commands): void;
}
