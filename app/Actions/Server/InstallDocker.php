<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Models\StandaloneDocker;
use Lorisleiva\Actions\Concerns\AsAction;

class InstallDocker
{
    use AsAction;

    public function handle(Server $server)
    {
        $dockerVersion = config('constants.docker.minimum_required_version');
        $supported_os_type = $server->validateOS();
        if (! $supported_os_type) {
            throw new \Exception('Server OS type is not supported for automated installation. Please install Docker manually before continuing: <a target="_blank" class="underline" href="https://coolify.io/docs/installation#manually">documentation</a>.');
        }
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
        $command = collect([]);
        if (isDev() && $server->id === 0) {
            $command = $command->merge([
                "echo 'Installing Prerequisites...'",
                'sleep 1',
                "echo 'Installing Docker Engine...'",
                "echo 'Configuring Docker Engine (merging existing configuration with the required)...'",
                'sleep 4',
                "echo 'Restarting Docker Engine...'",
                'ls -l /tmp',
            ]);

            return remote_process($command, $server);
        } else {
            if ($supported_os_type->contains('debian')) {
                $command = $command->merge([
                    "echo 'Installing Prerequisites...'",
                    'apt-get update -y',
                    'command -v curl >/dev/null || apt install -y curl',
                    'command -v wget >/dev/null || apt install -y wget',
                    'command -v git >/dev/null || apt install -y git',
                    'command -v jq >/dev/null || apt install -y jq',
                ]);
            } elseif ($supported_os_type->contains('rhel')) {
                $command = $command->merge([
                    "echo 'Installing Prerequisites...'",
                    'command -v curl >/dev/null || dnf install -y curl',
                    'command -v wget >/dev/null || dnf install -y wget',
                    'command -v git >/dev/null || dnf install -y git',
                    'command -v jq >/dev/null || dnf install -y jq',
                ]);
            } elseif ($supported_os_type->contains('sles')) {
                $command = $command->merge([
                    "echo 'Installing Prerequisites...'",
                    'zypper update -y',
                    'command -v curl >/dev/null || zypper install -y curl',
                    'command -v wget >/dev/null || zypper install -y wget',
                    'command -v git >/dev/null || zypper install -y git',
                    'command -v jq >/dev/null || zypper install -y jq',
                ]);
            } else {
                throw new \Exception('Unsupported OS');
            }
            $command = $command->merge([
                "echo 'Installing Docker Engine...'",
                "curl https://releases.rancher.com/install-docker/{$dockerVersion}.sh | sh || curl https://get.docker.com | sh -s -- --version {$dockerVersion}",
                "echo 'Configuring Docker Engine (merging existing configuration with the required)...'",
                'test -s /etc/docker/daemon.json && cp /etc/docker/daemon.json "/etc/docker/daemon.json.original-$(date +"%Y%m%d-%H%M%S")"',
                "test ! -s /etc/docker/daemon.json && echo '{$config}' | base64 -d | tee /etc/docker/daemon.json > /dev/null",
                "echo '{$config}' | base64 -d | tee /etc/docker/daemon.json.coolify > /dev/null",
                'jq . /etc/docker/daemon.json.coolify | tee /etc/docker/daemon.json.coolify.pretty > /dev/null',
                'mv /etc/docker/daemon.json.coolify.pretty /etc/docker/daemon.json.coolify',
                "jq -s '.[0] * .[1]' /etc/docker/daemon.json.coolify /etc/docker/daemon.json | tee /etc/docker/daemon.json.appended > /dev/null",
                'mv /etc/docker/daemon.json.appended /etc/docker/daemon.json',
                "echo 'Restarting Docker Engine...'",
                'systemctl enable docker >/dev/null 2>&1 || true',
                'systemctl restart docker',
            ]);
            if ($server->isSwarm()) {
                $command = $command->merge([
                    'docker network create --attachable --driver overlay coolify-overlay >/dev/null 2>&1 || true',
                ]);
            } else {
                $command = $command->merge([
                    'docker network create --attachable coolify >/dev/null 2>&1 || true',
                ]);
                $command = $command->merge([
                    "echo 'Done!'",
                ]);
            }

            return remote_process($command, $server);
        }
    }
}
