<?php

namespace App\Services\Contracts\Remote;

use App\Models\Server;
use Illuminate\Support\Collection;

interface RemoteCommandContract
{
    public function executeRemoteCommand(array $commands): void;
}
