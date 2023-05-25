<?php

namespace App\Actions\Server;

use App\Enums\ActivityTypes;
use App\Models\Server;

class InstallDocker
{
    public function __invoke(Server $server)
    {
        $config = base64_encode('{ "live-restore": true }');
        $activity = remote_process([
            "echo Installing Docker...",
            "curl https://releases.rancher.com/install-docker/23.0.sh | sh",
            "echo Configuring Docker...",
            "echo '{$config}' | base64 -d > /etc/docker/daemon.json",
            "echo Restarting Docker...",
            "systemctl restart docker"
        ], $server);

        return $activity;
    }
}
