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

    /**
     * Start the VM of a profile, creating it only when its libvirt domain does not exist yet.
     * With $fresh, only this profile's VM is destroyed, undefined, and deleted before it is created again.
     * With $gpu, the NVIDIA GPU of the host is passed through to the VM. An existing VM is stopped to add it.
     */
    public function handle(string $profileName, bool $fresh = false, bool $gpu = false): void
    {
        $this->ensureDevelopmentEnvironment();
        $profile = config("development-qemu.profiles.{$profileName}");

        if (! is_array($profile)) {
            throw new InvalidArgumentException("Unknown development QEMU profile: {$profileName}");
        }

        ConfigureDevelopmentQemuHost::run();
        $this->configureDhcpReservation($profile);

        if ($fresh) {
            $this->destroyVm($profile['domain']);
        }

        if (! $fresh && $this->domainExists($profile['domain'])) {
            if ($gpu) {
                $this->attachGpu($profile['domain']);
            }
            $this->startExistingVm($profile['domain']);
        } else {
            $this->createPreparedVm($profile, $gpu);
        }

        ConfigureDevelopmentQemuHost::run();
        $this->waitForSsh($profile['ip']);
    }

    /** @param array{domain: string, template: string, ip: string, user: string, mac: string, image: string, image_url: string, os_variant: string, provisioner: string} $profile */
    private function createPreparedVm(array $profile, bool $gpu): void
    {
        $preparedImage = $this->preparedImage($profile['template']);

        if (! File::exists($preparedImage)) {
            $this->createVm($profile, false);
            $this->waitForShutOff($profile['domain'], 900);
            $this->runOrFail('virsh undefine '.escapeshellarg($profile['domain']));
            $this->runOrFail('mv '.escapeshellarg($this->vmDisk($profile['domain'])).' '.escapeshellarg($preparedImage));
            if (File::exists($preparedImage)) {
                File::chmod($preparedImage, 0644);
            }
        }

        $this->createVm($profile, true, $gpu);
    }

    private function domainExists(string $domain): bool
    {
        return Process::run('virsh dominfo '.escapeshellarg($domain))->successful();
    }

    private function startExistingVm(string $domain): void
    {
        $state = trim(Process::run('virsh domstate '.escapeshellarg($domain))->output());

        if ($state === 'running') {
            return;
        }

        if ($state === 'paused') {
            $this->runOrFail('virsh resume '.escapeshellarg($domain));

            return;
        }

        $hasGpu = $this->hasGpu($domain);
        $this->applyMemory($domain, $hasGpu);

        if ($hasGpu) {
            $this->releaseHostGpu();
        }

        $this->runOrFail('virsh start '.escapeshellarg($domain));
    }

    /**
     * Sets the configured memory on a stopped VM, so VMs created with a larger size shrink on their next start.
     */
    private function applyMemory(string $domain, bool $hasGpu): void
    {
        $memory = (int) config($hasGpu ? 'development-qemu.gpu_memory' : 'development-qemu.memory').'M';
        $this->runOrFail(sprintf('virsh setmaxmem %s %s --config', escapeshellarg($domain), $memory));
        $this->runOrFail(sprintf('virsh setmem %s %s --config', escapeshellarg($domain), $memory));
    }

    private function hasGpu(string $domain): bool
    {
        return str_contains(Process::run('virsh dumpxml --inactive '.escapeshellarg($domain))->output(), '<hostdev ');
    }

    /**
     * Adds the GPU to the persistent definition of an existing VM. A running VM is shut down first.
     */
    private function attachGpu(string $domain): void
    {
        if ($this->hasGpu($domain)) {
            return;
        }

        if (trim(Process::run('virsh domstate '.escapeshellarg($domain))->output()) !== 'shut off') {
            $this->runOrFail('virsh shutdown '.escapeshellarg($domain));
            $this->waitForShutOff($domain, 120);
        }

        foreach ($this->hostGpuDevices() as $device) {
            $this->runOrFail(sprintf('virt-xml %s --add-device --hostdev %s', escapeshellarg($domain), escapeshellarg($device)));
        }
    }

    /**
     * Returns the libvirt node device names of every PCI function of the first NVIDIA display controller,
     * for example pci_0000_01_00_0 (GPU) and pci_0000_01_00_1 (its audio device). All functions must move together.
     *
     * @return array<int, string>
     */
    private function hostGpuDevices(): array
    {
        $devices = collect(explode("\n", trim(Process::run('lspci -Dnmm -d 10de:')->output())))
            ->filter()
            ->map(fn (string $line): array => str_getcsv($line, ' '));
        $gpu = $devices->first(fn (array $device): bool => in_array($device[1] ?? null, ['0300', '0302'], true));

        if ($gpu === null) {
            throw new RuntimeException('No NVIDIA GPU found on this host.');
        }

        $slot = substr($gpu[0], 0, strrpos($gpu[0], '.'));

        return $devices
            ->filter(fn (array $device): bool => str_starts_with($device[0], "{$slot}."))
            ->map(fn (array $device): string => 'pci_'.str_replace([':', '.'], '_', $device[0]))
            ->values()
            ->all();
    }

    /**
     * nvidia-persistenced keeps the GPU open, which blocks libvirt from moving it to vfio-pci.
     */
    private function releaseHostGpu(): void
    {
        Process::run('systemctl stop nvidia-persistenced');
    }

    private function destroyVm(string $domain): void
    {
        Process::run('virsh destroy '.escapeshellarg($domain));
        Process::run('virsh undefine '.escapeshellarg($domain));
        $this->deleteVmData($domain);
    }

    /** @param array{domain: string, template: string, ip: string, user: string, mac: string, image: string, image_url: string, os_variant: string, provisioner: string} $profile */
    private function createVm(array $profile, bool $prepared, bool $gpu = false): void
    {
        $directory = config('development-qemu.storage_path');
        File::ensureDirectoryExists($directory);
        File::chmod($directory, 0777);
        $this->moveLegacyFiles($directory);
        $baseImage = $prepared ? $this->preparedImage($profile['template']) : "{$directory}/{$profile['image']}";
        $disk = $this->vmDisk($profile['domain']);
        $userData = "{$directory}/{$profile['domain']}-user-data.yaml";
        $metaData = "{$directory}/{$profile['domain']}-meta-data.yaml";
        $networkConfig = "{$directory}/{$profile['domain']}-network.yaml";
        $seedImage = "{$directory}/{$profile['domain']}-seed.iso";

        if (! $prepared && ! File::exists($baseImage)) {
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

        if (! $prepared) {
            File::put($userData, $this->userData($profile));
            File::put($metaData, "instance-id: {$profile['domain']}\nlocal-hostname: {$profile['template']}\n");
            File::put($networkConfig, $this->networkConfig($profile));
            $this->runOrFail(sprintf(
                'xorriso -as mkisofs -V cidata -graft-points -o %s %s %s %s',
                escapeshellarg($seedImage),
                escapeshellarg('user-data='.$userData),
                escapeshellarg('meta-data='.$metaData),
                escapeshellarg('network-config='.$networkConfig),
            ));
        }

        $seedDisk = $prepared ? '' : ' --disk path='.escapeshellarg($seedImage).',format=raw,bus=virtio,readonly=on';
        // Instance networks are isolated, so VMs of different instances may share the profile MAC.
        $macCheck = config('development-qemu.instance') === null ? '' : ' --check mac_in_use=off';
        $hostDevices = '';

        if ($gpu) {
            $hostDevices = collect($this->hostGpuDevices())->map(fn (string $device): string => ' --hostdev '.escapeshellarg($device))->implode('');
            $this->releaseHostGpu();
        }

        $this->runOrFail(sprintf(
            'virt-install --connect qemu:///system --name %s --memory %d --vcpus %d --import --os-variant %s --disk path=%s,format=qcow2,bus=virtio%s --network network=%s,model=virtio,mac=%s --noautoconsole'.$macCheck.$hostDevices,
            escapeshellarg($profile['domain']),
            config($gpu ? 'development-qemu.gpu_memory' : 'development-qemu.memory'),
            config('development-qemu.vcpus'),
            escapeshellarg($profile['os_variant']),
            escapeshellarg($disk),
            $seedDisk,
            escapeshellarg(config('development-qemu.libvirt_network')),
            escapeshellarg($profile['mac']),
        ));
    }

    private function vmDisk(string $domain): string
    {
        return config('development-qemu.storage_path')."/{$domain}.qcow2";
    }

    /**
     * Prepared images are keyed by the instance-independent template name so every dev instance reuses them.
     */
    private function preparedImage(string $template): string
    {
        return config('development-qemu.storage_path')."/{$template}-prepared.qcow2";
    }

    private function waitForShutOff(string $domain, int $timeoutSeconds): void
    {
        $check = 'while [ "$(virsh domstate '.escapeshellarg($domain).')" != "shut off" ]; do sleep 2; done';
        $this->runOrFail("timeout {$timeoutSeconds} bash -c ".escapeshellarg($check));
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
            "{$directory}/{$domain}-meta-data.yaml",
            "{$directory}/{$domain}-network.yaml",
            "{$directory}/{$domain}-seed.iso",
        ]);
    }

    /** @param array{user: string, provisioner: string} $profile */
    private function userData(array $profile): string
    {
        $publicKey = config('development-qemu.public_key');
        $adminGroup = $profile['provisioner'] === 'apt' ? 'sudo' : 'wheel';
        $sudo = $profile['user'] === 'root' ? '' : "    groups: [{$adminGroup}]\n    sudo: ALL=(ALL) NOPASSWD:ALL\n";
        $shell = $profile['provisioner'] === 'apk' ? '/bin/ash' : '/bin/bash';
        $password = $profile['provisioner'] === 'apk'
            ? '    passwd: $6$dd2d71373a57c9ac$8.GUqZYlL/QqUmpUuupWfTuKQjNKT7kO31K5cp7OIY5SbBamlAVkJnBDYsIVimMaBrUtYfFjX3u6hzts3nKaD.'."\n    lock_passwd: false"
            : '    lock_passwd: true';

        [$packages, $installDocker, $startDocker] = match ($profile['provisioner']) {
            'apk' => ["  - docker\n  - docker-cli-buildx\n  - docker-cli-compose\n  - sudo", '', 'rc-update add cgroups boot && service cgroups start && rc-update add docker default && service docker start'],
            default => ["  - curl\n  - sudo", "curl -fsSL https://get.docker.com -o /tmp/get-docker.sh\n    sh /tmp/get-docker.sh\n    ", 'systemctl enable --now docker'],
        };
        $addUserToDockerGroup = $profile['user'] === 'root' ? '' : ($profile['provisioner'] === 'apk'
            ? "addgroup {$profile['user']} docker"
            : "usermod -aG docker {$profile['user']}");

        return <<<YAML
#cloud-config
disable_root: false
users:
  - name: {$profile['user']}
{$sudo}    shell: {$shell}
{$password}
    ssh_authorized_keys:
      - {$publicKey}
package_update: true
packages:
{$packages}
runcmd:
  - |
    set -eu
    {$installDocker}{$startDocker}
    {$addUserToDockerGroup}
    attempt=0
    until docker info >/dev/null 2>&1; do
      attempt=\$((attempt + 1))
      [ "\$attempt" -lt 60 ] || exit 1
      sleep 2
    done
    docker version
    docker info
    docker compose version
    docker buildx version
    touch /etc/cloud/cloud-init.disabled
    poweroff
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

    /** @param array{template: string, ip: string, mac: string} $profile */
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

        $host = sprintf("<host mac='%s' name='%s' ip='%s'/>", $profile['mac'], $profile['template'], $profile['ip']);
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
