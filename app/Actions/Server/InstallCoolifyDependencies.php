<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Models\StandaloneDocker;
use Lorisleiva\Actions\Concerns\AsAction;

class InstallCoolifyDependencies
{
    use AsAction;

    public function handle(Server $server, string $supported_os_type)
    {
        $supported_os_type = trim(strtolower($supported_os_type));

        switch ($supported_os_type) {
            case 'manjaro':
            case 'manjaro-arm':
                $supported_os_type = 'arch';
                break;
            case 'fedora-asahi-remix':
                $supported_os_type = 'fedora';
                break;
            case 'pop':
            case 'linuxmint':
            case 'zorin':
                $supported_os_type = 'ubuntu';
                break;
        }

        $dockerVersion = config('constants.docker.version');

        $config = base64_encode('{
            "log-driver": "json-file",
            "log-opts": {
              "max-size": "10m",
              "max-file": "3"
            },
            "default-address-pools": [
              {"base":"10.0.0.0/8","size":24}
            ]
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

        switch ($supported_os_type) {
            case 'ubuntu':
            case 'debian':
            case 'raspbian':
                $command = $command->merge([
                    "echo 'Installing Prerequisites...'",
                    'apt-get update -y',
                    'command -v curl >/dev/null || apt install -y curl',
                    'command -v wget >/dev/null || apt install -y wget',
                    'command -v git >/dev/null || apt install -y git',
                    'command -v jq >/dev/null || apt install -y jq',
                    'command -v qemu-user-static >/dev/null || apt install -y qemu-user-static binfmt-support',
                ]);
                break;

            case 'centos':
            case 'fedora':
            case 'rhel':
            case 'ol':
            case 'rocky':
            case 'almalinux':
            case 'amzn':
                if ($supported_os_type === 'amzn') {
                    $command = $command->merge([
                        'dnf install -y wget git jq openssl qemu-user-static',
                    ]);
                } else {
                    $command = $command->merge([
                        "echo 'Installing Prerequisites...'",
                        'command -v dnf >/dev/null || yum install -y dnf',
                        'command -v curl >/dev/null || dnf install -y curl',
                        'dnf install -y wget git jq openssl qemu-user-static',
                    ]);
                }
                break;

            case 'arch':
            case 'archarm':
                $command = $command->merge([
                    "echo 'Installing Prerequisites...'",
                    'pacman -Sy --noconfirm --needed curl wget git jq openssl qemu-user-static',
                ]);
                break;

            case 'alpine':
                $command = $command->merge([
                    "echo 'Installing Prerequisites...'",
                    'sed -i \'/^#.*\/community/s/^#//\' /etc/apk/repositories',
                    'apk update',
                    'apk add curl wget git jq openssl qemu-user',
                ]);
                break;

            case 'sles':
            case 'opensuse-leap':
            case 'opensuse-tumbleweed':
                $command = $command->merge([
                    "echo 'Installing Prerequisites...'",
                    'zypper refresh',
                    'zypper install -y curl wget git jq openssl qemu-linux-user',
                ]);
                break;

            default:
                throw new \Exception('This script only supports Debian, Redhat, Arch Linux, Alpine Linux, or SLES based operating systems.');
        }

        $command = $command->merge([
            "echo 'Installing Docker Engine...'",
            "curl https://releases.rancher.com/install-docker/{$dockerVersion}.sh | sh || curl https://get.docker.com | sh -s -- --version {$dockerVersion}",
            "echo 'Configuring Docker Engine (merging existing configuration with the required)...'",
            'mkdir -p /etc/docker',
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

            "echo 'Installing multi-architecture support...'",
            'docker run --privileged --rm tonistiigi/binfmt --install all >/dev/null 2>&1 || echo "Warning: Failed to install multi-architecture support."',
        ]);

        if ($server->isSwarm()) {
            $command = $command->merge([
                'docker network create --attachable --driver overlay coolify-overlay >/dev/null 2>&1 || true',
            ]);
        } else {
            $command = $command->merge([
                'docker network create --attachable coolify >/dev/null 2>&1 || true',
                "echo 'Done!'",
            ]);
        }

        return remote_process($command, $server);
    }
}
