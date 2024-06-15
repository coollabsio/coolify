<?php

namespace App\Services\Docker;

use App\Models\Server;
use App\Services\Remote\Provider\RemoteProcessProvider;

class DockerProvider
{
    private RemoteProcessProvider $remoteProcessProvider;

    public function __construct(RemoteProcessProvider $remoteProcessProvider)
    {
        $this->remoteProcessProvider = $remoteProcessProvider;
    }

    public function forServer(Server $server): DockerHelper
    {
        return new DockerHelper($server, $this->remoteProcessProvider);
    }
}
