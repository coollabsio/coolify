<?php

namespace App\Actions\Server;

use App\Helpers\SslHelper;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Lorisleiva\Actions\Concerns\AsAction;

class InstallDocker
{
    use AsAction;

    private string $dockerVersion;

    public function handle(Server $server)
    {
        $this->dockerVersion = config('constants.docker.minimum_required_version');
        $supported_os_type = $server->validateOS();
        if (! $supported_os_type) {
            throw new \Exception('Server OS type is not supported for automated installation. Please install Docker manually before continuing: <a target="_blank" class="underline" href="https://coolify.io/docs/installation#manually">documentation</a>.');
        }

        if (! $server->sslCertificates()->where('is_ca_certificate', true)->exists()) {
            $serverCert = SslHelper::generateSslCertificate(
                commonName: 'Coolify CA Certificate',
                serverId: $server->id,
                isCaCertificate: true,
                validityDays: 10 * 365
            );
            $caCertPath = config('constants.coolify.base_config_path').'/ssl/';

            $commands = collect([
                "mkdir -p $caCertPath",
                "chown -R 9999:root $caCertPath",
                "chmod -R 700 $caCertPath",
                "rm -rf $caCertPath/coolify-ca.crt",
                "echo '{$serverCert->ssl_certificate}' > $caCertPath/coolify-ca.crt",
                "chmod 644 $caCertPath/coolify-ca.crt",
            ]);
            remote_process($commands, $server);
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
                "echo 'Installing Docker Engine...'",
                "echo 'Configuring Docker Engine (merging existing configuration with the required)...'",
                'sleep 4',
                "echo 'Restarting Docker Engine...'",
                'ls -l /tmp',
            ]);

            return remote_process($command, $server);
        } else {
            $command = $command->merge([
                "echo 'Installing Docker Engine...'",
            ]);

            if ($supported_os_type->contains('debian')) {
                $command = $command->merge([$this->getDebianDockerInstallCommand()]);
            } elseif ($supported_os_type->contains('rhel')) {
                $command = $command->merge([$this->getRhelDockerInstallCommand()]);
            } elseif ($supported_os_type->contains('sles')) {
                $command = $command->merge([$this->getSuseDockerInstallCommand()]);
            } elseif ($supported_os_type->contains('arch')) {
                $command = $command->merge([$this->getArchDockerInstallCommand()]);
            } else {
                $command = $command->merge([$this->getGenericDockerInstallCommand()]);
            }

            $command = $command->merge([
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

    private function getDebianDockerInstallCommand(): string
    {
        return "curl --max-time 300 --retry 3 https://releases.rancher.com/install-docker/{$this->dockerVersion}.sh | sh || curl --max-time 300 --retry 3 https://get.docker.com | sh -s -- --version {$this->dockerVersion} || (".
            'install -m 0755 -d /etc/apt/keyrings && '.
            'curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc && '.
            'chmod a+r /etc/apt/keyrings/docker.asc && '.
            '. /etc/os-release && '.
            'echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian ${VERSION_CODENAME} stable" > /etc/apt/sources.list.d/docker.list && '.
            'apt-get update && '.
            'apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin'.
            ')';
    }

    private function getRhelDockerInstallCommand(): string
    {
        return "curl https://releases.rancher.com/install-docker/{$this->dockerVersion}.sh | sh || curl https://get.docker.com | sh -s -- --version {$this->dockerVersion} || (".
            'dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo && '.
            'dnf install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin && '.
            'systemctl start docker && '.
            'systemctl enable docker'.
            ')';
    }

    private function getSuseDockerInstallCommand(): string
    {
        return "curl https://releases.rancher.com/install-docker/{$this->dockerVersion}.sh | sh || curl https://get.docker.com | sh -s -- --version {$this->dockerVersion} || (".
            'zypper addrepo https://download.docker.com/linux/sles/docker-ce.repo && '.
            'zypper refresh && '.
            'zypper install -y --no-confirm docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin && '.
            'systemctl start docker && '.
            'systemctl enable docker'.
            ')';
    }

    private function getArchDockerInstallCommand(): string
    {
        // Use -Syu to perform full system upgrade before installing Docker
        // Partial upgrades (-Sy without -u) are discouraged on Arch Linux
        // as they can lead to broken dependencies and system instability
        // Use --needed to skip reinstalling packages that are already up-to-date (idempotent)
        return 'pacman -Syu --noconfirm --needed docker docker-compose && '.
            'systemctl enable docker.service && '.
            'systemctl start docker.service';
    }

    private function getGenericDockerInstallCommand(): string
    {
        return "curl --max-time 300 --retry 3 https://releases.rancher.com/install-docker/{$this->dockerVersion}.sh | sh || curl --max-time 300 --retry 3 https://get.docker.com | sh -s -- --version {$this->dockerVersion}";
    }
}
