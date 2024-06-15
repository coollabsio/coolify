<?php

namespace App\Services\Deployment;

use App\Models\Server;
use App\Services\Remote\InstantRemoteProcessFactory;
use App\Services\Remote\Provider\RemoteProcessProvider;
use App\Services\Remote\RemoteProcessExecutionerManager;

class DeploymentProvider
{
    private RemoteProcessProvider $remoteProcessProvider;

    private InstantRemoteProcessFactory $instantRemoteProcessFactory;

    private RemoteProcessExecutionerManager $executioner;

    public function __construct(RemoteProcessProvider $remoteProcessProvider, InstantRemoteProcessFactory $instantRemoteProcessFactory, RemoteProcessExecutionerManager $executionerManager)
    {
        $this->remoteProcessProvider = $remoteProcessProvider;
        $this->instantRemoteProcessFactory = $instantRemoteProcessFactory;
        $this->executioner = $executionerManager;

    }

    public function forServer(Server $server): DeploymentHelper
    {
        return new DeploymentHelper($server, $this->remoteProcessProvider, $this->instantRemoteProcessFactory, $this->executioner);
    }
}
