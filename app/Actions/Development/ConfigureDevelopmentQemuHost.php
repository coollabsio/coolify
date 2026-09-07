<?php

namespace App\Actions\Development;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class ConfigureDevelopmentQemuHost
{
    use AsAction;

    public function handle(): void
    {
        $this->ensureDevelopmentEnvironment();
        $this->installDependencies();
        $this->runOrFail('systemctl enable --now libvirtd');
        $this->configureLibvirtNetwork();
        $this->configureIpForwarding();
        $this->configureStorage();
        $this->configureDockerForwarding();
    }

    private function installDependencies(): void
    {
        $binaries = ['curl', 'docker', 'iptables', 'qemu-img', 'virsh', 'virt-install'];
        $check = collect($binaries)->map(fn (string $binary) => 'command -v '.escapeshellarg($binary))->implode(' && ');

        if (Process::run($check)->successful()) {
            return;
        }

        if (! File::exists('/usr/bin/apt-get')) {
            throw new RuntimeException('Missing QEMU dependencies. Automatic installation currently supports apt-based development hosts.');
        }

        $this->runOrFail('apt-get update');
        $this->runOrFail('DEBIAN_FRONTEND=noninteractive apt-get install -y curl iptables libvirt-clients libvirt-daemon-system qemu-utils qemu-system-x86 virtinst');
    }

    private function configureLibvirtNetwork(): void
    {
        $network = config('development-qemu.libvirt_network');
        $networkInfo = Process::run('virsh net-info '.escapeshellarg($network));

        if ($networkInfo->failed()) {
            $networkXml = config('development-qemu.storage_path').'/libvirt-network.xml';
            File::ensureDirectoryExists(dirname($networkXml), 0777, true);
            File::put($networkXml, $this->libvirtNetworkXml($network));
            $this->runOrFail('virsh net-define '.escapeshellarg($networkXml));
            $networkInfo = Process::result(output: 'Active: no');
        }

        if (! preg_match('/^Active:\s+yes$/m', $networkInfo->output())) {
            $this->runOrFail('virsh net-start '.escapeshellarg($network));
        }

        $this->runOrFail('virsh net-autostart '.escapeshellarg($network));
    }

    private function configureIpForwarding(): void
    {
        $this->runOrFail("printf 'net.ipv4.ip_forward=1\\n' > /etc/sysctl.d/99-coolify-development-qemu.conf");
        $this->runOrFail('sysctl -w net.ipv4.ip_forward=1');
    }

    private function configureStorage(): void
    {
        $directory = config('development-qemu.storage_path');
        File::ensureDirectoryExists($directory, 0777, true);
        File::chmod($directory, 0777);
    }

    private function configureDockerForwarding(): void
    {
        $dockerNetwork = escapeshellarg(config('development-qemu.docker_network'));
        $subnetResult = Process::run("docker network inspect {$dockerNetwork} --format ".escapeshellarg('{{(index .IPAM.Config 0).Subnet}}'));
        $subnet = trim($subnetResult->output());

        if ($subnetResult->failed() || $subnet === '') {
            throw new RuntimeException('Unable to determine the Coolify Docker network subnet.');
        }

        $rule = sprintf('-s %s -d %s -o virbr0 -j ACCEPT', escapeshellarg($subnet), escapeshellarg(config('development-qemu.subnet')));

        Process::run("iptables -D LIBVIRT_FWI {$rule}");
        $this->runOrFail("iptables -I LIBVIRT_FWI 1 {$rule}");
    }

    private function libvirtNetworkXml(string $network): string
    {
        return <<<XML
<network>
  <name>{$network}</name>
  <forward mode="nat"/>
  <bridge name="virbr0" stp="on" delay="0"/>
  <ip address="192.168.122.1" netmask="255.255.255.0">
    <dhcp>
      <range start="192.168.122.2" end="192.168.122.254"/>
    </dhcp>
  </ip>
</network>
XML;
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
            throw new RuntimeException('QEMU host configuration may only run in development environments.');
        }
    }
}
