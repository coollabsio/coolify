<?php

namespace App\Services\Contracts\Remote;

interface RemoteCommandContract
{
    public function executeRemoteCommand(array $commands): void;
}
