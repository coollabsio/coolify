<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

/**
 * Installs the Sysbox container runtime (https://github.com/nestybox/sysbox) from the pinned .deb package.
 * Sysbox lets GitHub runners use Docker without privileged containers.
 *
 * The package restarts Docker, or stops when containers exist, unless /etc/docker/daemon.json already sets
 * "bip" and "default-address-pools". The installer writes the current values of both first, so the package
 * only reloads Docker and running containers are not affected.
 */
class InstallSysbox
{
    use AsAction;

    public const RUNTIME = 'sysbox-runc';

    /** ID-mapped mounts make shiftfs unnecessary from this kernel version. */
    private const MINIMUM_KERNEL = '5.12';

    private const DAEMON_CONFIG = '/etc/docker/daemon.json';

    public function handle(Server $server): Activity
    {
        if (! filled(instant_remote_process(['command -v apt-get || true'], $server))) {
            throw new RuntimeException('Automatic Sysbox installation is only available on Debian and Ubuntu servers. Install Sysbox manually: https://github.com/nestybox/sysbox/blob/master/docs/user-guide/install-package.md');
        }

        $architecture = trim((string) instant_remote_process(['dpkg --print-architecture'], $server));
        if (! in_array($architecture, ['amd64', 'arm64'], true)) {
            throw new RuntimeException("Sysbox packages are not available for the {$architecture} architecture.");
        }

        $kernel = trim((string) instant_remote_process(['uname -r'], $server));
        if (! self::isKernelSupported($kernel)) {
            throw new RuntimeException('Sysbox needs Linux kernel '.self::MINIMUM_KERNEL." or newer. This server runs {$kernel}.");
        }

        $daemonConfig = self::daemonConfigWithNetworkSettings(
            instant_remote_process(['cat '.self::DAEMON_CONFIG.' 2>/dev/null || true'], $server),
            (string) instant_remote_process(["docker network inspect bridge --format '{{range .IPAM.Config}}{{.Gateway}} {{.Subnet}}{{end}}'"], $server),
        );

        return remote_process(self::installCommands($architecture, $daemonConfig), $server);
    }

    /**
     * Sysbox must be registered in Docker, its services must run, and FUSE 3 must be available.
     */
    public static function isInstalled(Server $server): bool
    {
        $runtimes = instant_remote_process([self::installedCheckCommand()], $server, false);

        return in_array(self::RUNTIME, explode(' ', trim((string) $runtimes)), true);
    }

    /**
     * Prints the Docker runtimes when the Sysbox services run and FUSE 3 is available.
     */
    public static function installedCheckCommand(): string
    {
        return "systemctl is-active --quiet sysbox-mgr sysbox-fs && test -x /usr/bin/fusermount3 && docker info --format '{{range \$name, \$runtime := .Runtimes}}{{\$name}} {{end}}'";
    }

    public static function isKernelSupported(string $kernel): bool
    {
        if (! preg_match('/\A(\d+)\.(\d+)/', $kernel, $matches)) {
            return false;
        }

        return version_compare("{$matches[1]}.{$matches[2]}", self::MINIMUM_KERNEL, '>=');
    }

    /**
     * Returns the Docker daemon configuration with "bip" and "default-address-pools" set to the values that
     * Docker already uses, or null when both are already set.
     *
     * @param  string  $bridge  "<gateway> <subnet>" of the default bridge network, for example "172.17.0.1 172.17.0.0/16".
     */
    public static function daemonConfigWithNetworkSettings(?string $currentConfig, string $bridge): ?string
    {
        $config = filled(trim((string) $currentConfig)) ? json_decode($currentConfig) : new \stdClass;
        if (! $config instanceof \stdClass) {
            throw new RuntimeException(self::DAEMON_CONFIG.' is not a valid JSON object. Fix it, then install Sysbox again.');
        }

        $hasBip = filled($config->bip ?? null);
        $hasAddressPools = filled($config->{'default-address-pools'} ?? null);
        if ($hasBip && $hasAddressPools) {
            return null;
        }

        if (! $hasBip) {
            if (! preg_match('/\A(\d{1,3}(?:\.\d{1,3}){3}) \d{1,3}(?:\.\d{1,3}){3}\/(\d{1,2})\z/', trim($bridge), $matches)) {
                throw new RuntimeException('Could not read the address of the default Docker bridge network.');
            }
            $config->bip = "{$matches[1]}/{$matches[2]}";
        }

        if (! $hasAddressPools) {
            $config->{'default-address-pools'} = self::dockerDefaultAddressPools();
        }

        // Sysbox checks both keys with a regex that expects one key per indented line.
        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * @return array<int, string>
     */
    public static function installCommands(string $architecture, ?string $daemonConfig): array
    {
        $version = config('constants.github_runner.sysbox.version');
        $checksum = config("constants.github_runner.sysbox.checksums.{$architecture}");
        $package = "/tmp/sysbox-ce_{$version}_{$architecture}.deb";
        $url = "https://github.com/nestybox/sysbox/releases/download/v{$version}/sysbox-ce_{$version}.linux_{$architecture}.deb";
        $apt = 'DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300';

        $commands = ["echo 'Installing Sysbox {$version}.'"];
        if ($daemonConfig !== null) {
            $commands[] = "echo 'Saving the current Docker network settings in ".self::DAEMON_CONFIG." so that Docker does not restart.'";
            $commands[] = 'mkdir -p /etc/docker';
            $commands[] = 'cp '.self::DAEMON_CONFIG.' '.self::DAEMON_CONFIG.'.before-sysbox 2>/dev/null || true';
            $commands[] = 'echo '.base64_encode($daemonConfig).' | base64 -d | tee '.self::DAEMON_CONFIG.' > /dev/null';
        }

        return [
            ...$commands,
            "echo 'Downloading the Sysbox package.'",
            "curl -fsSL -o {$package} {$url}",
            "echo '{$checksum}  {$package}' | sha256sum -c -",
            "echo 'Installing the Sysbox package. Docker reloads its configuration, running containers are not restarted.'",
            "{$apt} update",
            // Sysbox needs fusermount3, but its "fuse" dependency is satisfied by FUSE 2 on Debian 12.
            "{$apt} install -y fuse3 {$package}",
            "rm -f {$package}",
            'systemctl is-active --quiet sysbox-mgr sysbox-fs',
            'test -x /usr/bin/fusermount3',
            "docker info --format '{{range \$name, \$runtime := .Runtimes}}{{\$name}} {{end}}' | grep -qw ".self::RUNTIME,
            "echo 'Sysbox is installed.'",
        ];
    }

    /**
     * The address pools that Docker uses when "default-address-pools" is not set.
     *
     * @return array<int, array{base: string, size: int}>
     */
    private static function dockerDefaultAddressPools(): array
    {
        $pools = [];
        foreach (range(17, 31) as $octet) {
            $pools[] = ['base' => "172.{$octet}.0.0/16", 'size' => 16];
        }
        $pools[] = ['base' => '192.168.0.0/16', 'size' => 20];

        return $pools;
    }
}
