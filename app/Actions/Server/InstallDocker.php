<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Models\StandaloneDocker;

class InstallDocker
{
    public function __invoke(Server $server)
    {
        $dockerVersion = '24.0';
        $config = base64_encode('{
            "log-driver": "json-file",
            "log-opts": {
              "max-size": "10m",
              "max-file": "3"
            }
          }');
        $found = StandaloneDocker::where('server_id', $server->id);
        if ($found->count() == 0 && $server->id) {
            StandaloneDocker::create([
                'name' => 'coolify',
                'network' => 'coolify',
                'server_id' => $server->id,
            ]);
        }

        if (isDev() && $server->id === 0) {
            $command = [
                "echo '####### Installing Prerequisites...'",
                "sleep 1",
                "echo '####### Installing/updating Docker Engine...'",
                "echo '####### Configuring Docker Engine (merging existing configuration with the required)...'",
                "sleep 4",
                "echo '####### Restarting Docker Engine...'",
                "ls -l /tmp"
            ];
        } else {
            $command = [
                "echo '####### Installing Prerequisites...'",
                "command -v jq >/dev/null || apt-get update",
                "command -v jq >/dev/null || apt install -y jq",
                "echo '####### Installing/updating Docker Engine...'",
                "curl https://releases.rancher.com/install-docker/{$dockerVersion}.sh | sh",
                "echo '####### Configuring Docker Engine (merging existing configuration with the required)...'",
                "test -s /etc/docker/daemon.json && cp /etc/docker/daemon.json \"/etc/docker/daemon.json.original-`date +\"%Y%m%d-%H%M%S\"`\" || echo '{$config}' | base64 -d > /etc/docker/daemon.json",
                "echo '{$config}' | base64 -d > /etc/docker/daemon.json.coolify",
                "cat <<< $(jq . /etc/docker/daemon.json.coolify) > /etc/docker/daemon.json.coolify",
                "cat <<< $(jq -s '.[0] * .[1]' /etc/docker/daemon.json /etc/docker/daemon.json.coolify) > /etc/docker/daemon.json",
                "echo '####### Restarting Docker Engine...'",
                "systemctl restart docker",
                "echo '####### Creating default Docker network (coolify)...'",
                "docker network create --attachable coolify >/dev/null 2>&1 || true",
                "echo '####### Done!'"
            ];
        }
        return remote_process($command, $server);
    }
}
