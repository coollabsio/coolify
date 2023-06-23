<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;

class InstallDocker
{
    public function __invoke(Server $server, Team $team)
    {
        $dockerVersion = '23.0';
        $config = base64_encode('{ "live-restore": true }');
        $activity = remote_process([
            "echo ####### Installing Prerequisites...",
            "command -v jq >/dev/null || apt-get update",
            "command -v jq >/dev/null || apt install -y jq",
            "echo ####### Installing/updating Docker Engine...",
            "curl https://releases.rancher.com/install-docker/{$dockerVersion}.sh | sh",
            "echo ####### Configuring Docker Engine (merging existing configuration with the required)...",
            "test -s /etc/docker/daemon.json && cp /etc/docker/daemon.json \"/etc/docker/daemon.json.original-`date +\"%Y%m%d-%H%M%S\"`\" || echo '{$config}' | base64 -d > /etc/docker/daemon.json",
            "echo '{$config}' | base64 -d > /etc/docker/daemon.json.coolify",
            "cat <<< $(jq . /etc/docker/daemon.json.coolify) > /etc/docker/daemon.json.coolify",
            "cat <<< $(jq -s '.[0] * .[1]' /etc/docker/daemon.json /etc/docker/daemon.json.coolify) > /etc/docker/daemon.json",
            "echo ####### Restarting Docker Engine...",
            "systemctl restart docker",
            "echo ####### Creating default network...",
            "docker network create --attachable coolify",
            "echo ####### Done!"
        ], $server);
        StandaloneDocker::create([
            'name' => 'coolify',
            'network' => 'coolify',
            'server_id' => $server->id,
            'team_id' => $team->id
        ]);

        return $activity;
    }
}
