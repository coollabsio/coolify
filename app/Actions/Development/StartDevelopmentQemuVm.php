<?php

namespace App\Actions\Development;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class StartDevelopmentQemuVm
{
    use AsAction;

    public function handle(string $profileName, bool $resetManagedVms = true): void
    {
        $this->ensureDevelopmentEnvironment();
        $profiles = config('development-qemu.profiles');
        $profile = $profiles[$profileName] ?? null;

        if (! is_array($profile)) {
            throw new InvalidArgumentException("Unknown development QEMU profile: {$profileName}");
        }

        ConfigureDevelopmentQemuHost::run();
        $this->configureDhcpReservation($profile);

        if ($resetManagedVms) {
            foreach ($profiles as $managedProfile) {
                Process::run('virsh destroy '.escapeshellarg($managedProfile['domain']));
                Process::run('virsh undefine '.escapeshellarg($managedProfile['domain']));
                $this->deleteVmData($managedProfile['domain']);
            }
        }

        $this->createVm($profile);

        ConfigureDevelopmentQemuHost::run();
        $this->waitForSsh($profile['ip']);
    }

    /** @param array{domain: string, ip: string, user: string, mac: string, image: string, image_url: string, os_variant: string, provisioner: string} $profile */
    private function createVm(array $profile): void
    {
        $directory = config('development-qemu.storage_path');
        File::ensureDirectoryExists($directory);
        File::chmod($directory, 0777);
        $this->moveLegacyFiles($directory);
        $baseImage = "{$directory}/{$profile['image']}";
        $disk = "{$directory}/{$profile['domain']}.qcow2";
        $userData = "{$directory}/{$profile['domain']}-user-data.yaml";
        $networkConfig = "{$directory}/{$profile['domain']}-network.yaml";

        if (! File::exists($baseImage)) {
            $this->runOrFail(sprintf(
                'curl --fail --location --output %s %s',
                escapeshellarg($baseImage),
                escapeshellarg($profile['image_url']),
            ));
        }

        if (! File::exists($disk)) {
            $this->runOrFail(sprintf(
                'qemu-img create -f qcow2 -F qcow2 -b %s %s %s',
                escapeshellarg($baseImage),
                escapeshellarg($disk),
                escapeshellarg(config('development-qemu.disk_size')),
            ));
        }

        if (File::exists($baseImage)) {
            File::chmod($baseImage, 0644);
        }

        if (File::exists($disk)) {
            File::chmod($disk, 0666);
        }

        File::put($userData, $this->userData($profile));
        File::put($networkConfig, $this->networkConfig($profile));

        $this->runOrFail(sprintf(
            'virt-install --connect qemu:///system --name %s --memory %d --vcpus %d --import --os-variant %s --disk path=%s,format=qcow2,bus=virtio --network network=%s,model=virtio,mac=%s --cloud-init user-data=%s,network-config=%s,disable=on --noautoconsole',
            escapeshellarg($profile['domain']),
            config('development-qemu.memory'),
            config('development-qemu.vcpus'),
            escapeshellarg($profile['os_variant']),
            escapeshellarg($disk),
            escapeshellarg(config('development-qemu.libvirt_network')),
            escapeshellarg($profile['mac']),
            escapeshellarg($userData),
            escapeshellarg($networkConfig),
        ));
    }

    private function moveLegacyFiles(string $directory): void
    {
        $legacyDirectory = storage_path('app/development-qemu');

        if ($legacyDirectory === $directory || ! File::isDirectory($legacyDirectory)) {
            return;
        }

        foreach (File::files($legacyDirectory) as $file) {
            $destination = "{$directory}/{$file->getFilename()}";

            if (! File::exists($destination)) {
                File::move($file->getPathname(), $destination);
            }
        }
    }

    private function deleteVmData(string $domain): void
    {
        $directory = config('development-qemu.storage_path');
        File::delete([
            "{$directory}/{$domain}.qcow2",
            "{$directory}/{$domain}-user-data.yaml",
            "{$directory}/{$domain}-network.yaml",
        ]);
    }

    /** @param array{user: string, provisioner: string} $profile */
    private function userData(array $profile): string
    {
        $publicKey = config('development-qemu.public_key');
        $adminGroup = $profile['provisioner'] === 'apt' ? 'sudo' : 'wheel';
        $sudo = $profile['user'] === 'root' ? '' : "    groups: [{$adminGroup}]\n    sudo: ALL=(ALL) NOPASSWD:ALL\n";

        [$packages, $startDocker] = match ($profile['provisioner']) {
            'apk' => ["  - docker\n  - sudo", 'rc-update add docker default && service docker start'],
            'rpm' => ["  - curl\n  - sudo", 'curl -fsSL https://get.docker.com | sh && systemctl enable --now docker'],
            default => ["  - docker.io\n  - sudo", 'systemctl enable --now docker'],
        };
        $addUserToDockerGroup = $profile['user'] === 'root' ? '' : "\n  - usermod -aG docker {$profile['user']}";

        return <<<YAML
#cloud-config
disable_root: false
users:
  - name: {$profile['user']}
{$sudo}    shell: /bin/bash
    lock_passwd: true
    ssh_authorized_keys:
      - {$publicKey}
package_update: true
packages:
{$packages}
runcmd:
  - {$startDocker}{$addUserToDockerGroup}
YAML;
    }

    /** @param array{mac: string} $profile */
    private function networkConfig(array $profile): string
    {
        return <<<YAML
version: 2
ethernets:
  default:
    match:
      macaddress: "{$profile['mac']}"
    dhcp4: true
YAML;
    }

    /** @param array{domain: string, ip: string, mac: string} $profile */
    private function configureDhcpReservation(array $profile): void
    {
        $network = escapeshellarg(config('development-qemu.libvirt_network'));
        $networkXml = Process::run("virsh net-dumpxml {$network}");

        if ($networkXml->failed()) {
            throw new RuntimeException(trim($networkXml->errorOutput()) ?: 'Unable to inspect the libvirt network.');
        }

        if (str_contains($networkXml->output(), $profile['mac']) && str_contains($networkXml->output(), $profile['ip'])) {
            return;
        }

        $host = sprintf("<host mac='%s' name='%s' ip='%s'/>", $profile['mac'], $profile['domain'], $profile['ip']);
        $this->runOrFail("virsh net-update {$network} add-last ip-dhcp-host ".escapeshellarg($host).' --live --config');
    }

    private function waitForSsh(string $ip): void
    {
        $container = escapeshellarg(config('development-qemu.coolify_container'));
        $probe = <<<'PHP'
$deadline = time() + 120;
do {
    $socket = @fsockopen($argv[1], 22, $errorCode, $errorMessage, 1);
    if (is_resource($socket)) {
        fclose($socket);
        exit(0);
    }
    sleep(1);
} while (time() < $deadline);
exit(1);
PHP;
        $this->runOrFail("docker exec {$container} php -r ".escapeshellarg($probe).' '.escapeshellarg($ip));
    }

    private function runOrFail(string $command): void
    {
        $result = Process::forever()->run($command);

        if ($result->failed()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: "Command failed: {$command}");
        }

    }

    private function ensureDevelopmentEnvironment(): void
    {
        if (! in_array(config('app.env'), ['local', 'development', 'dev'], true)) {
            throw new RuntimeException('QEMU VMs may only be managed in development environments.');
        }
    }
}
