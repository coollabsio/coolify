<?php

namespace App\Services\Remote\Provider;

use App\Models\Server;
use App\Services\Remote\InstantRemoteProcessFactory;
use App\Services\Remote\RemoteProcessExecutionerManager;
use App\Services\Remote\RemoteProcessManager;

class RemoteProcessProvider
{
    private InstantRemoteProcessFactory $instantRemoteProcessFactory;

    private RemoteProcessExecutionerManager $executioner;

    public function __construct(InstantRemoteProcessFactory $instantRemoteProcessFactory, RemoteProcessExecutionerManager $executionerManager)
    {
        $this->instantRemoteProcessFactory = $instantRemoteProcessFactory;
        $this->executioner = $executionerManager;
    }

    public function forServer(Server $server): RemoteProcessManager
    {
        return new RemoteProcessManager($server, $this->instantRemoteProcessFactory, $this->executioner);
    }
}
