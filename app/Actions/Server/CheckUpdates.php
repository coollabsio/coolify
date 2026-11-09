<?php

namespace App\Actions\Server;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class CheckUpdates
{
    use AsAction;

    public string $jobQueue = 'high';

    /**
     * Package managers that only support a full system upgrade. A partial upgrade on Arch Linux is not supported.
     *
     * @see https://wiki.archlinux.org/title/System_maintenance#Partial_upgrades_are_unsupported
     */
    public const FULL_UPGRADE_ONLY = ['pacman'];

    /**
     * Latest upgrade of each installed package. dnf5 does not add a newline after --qf, dnf4 does, so empty lines are skipped.
     */
    public const DNF_UPGRADES_COMMAND = 'LANG=C dnf repoquery -q --refresh --upgrades --latest-limit 1 --qf "%{name} %{arch} %{evr} %{repoid}\n"';

    /**
     * Simulations of `apt-get upgrade` without and with the phased updates. apt holds back an update in a phased rollout
     * until this machine is in the rollout, but `apt list --upgradable` still shows it. The difference of the two runs
     * gives the phased updates. Text matching is not possible: apt 2.4 (Ubuntu 22.04) prints them as "kept back".
     *
     * @see https://documentation.ubuntu.com/server/explanation/software/about-apt-upgrade-and-phased-updates/
     */
    public const APT_SIMULATE_UPGRADE_COMMAND = 'LANG=C apt-get -s upgrade 2>/dev/null';

    public const APT_SIMULATE_PHASED_UPGRADE_COMMAND = 'LANG=C apt-get -s -o APT::Get::Always-Include-Phased-Updates=true upgrade 2>/dev/null';

    /**
     * Packages held with `apt-mark hold`. `apt list --upgradable` shows their updates, but apt does not install them.
     */
    public const APT_HELD_COMMAND = 'apt-mark showhold 2>/dev/null';

    /**
     * Simulation of `apk upgrade`. A version pin in /etc/apk/world (`name=1.2-r0`, `name<2`, `name~1.2`) blocks an
     * update that `apk list --upgradable` still shows, and also the updates of packages that depend on that version.
     */
    public const APK_SIMULATE_UPGRADE_COMMAND = 'LANG=C apk upgrade -s 2>/dev/null';

    public const RPM_INSTALLED_COMMAND = 'rpm -qa --qf "%{NAME} %{ARCH} %{VERSION}-%{RELEASE}\n"';

    /**
     * Lists pacman updates without `pacman -Sy` on the live database (that would make the next single install a partial upgrade).
     * `checkupdates` needs fakeroot when it runs as root. Without it, the same sync runs into a temporary database.
     * pacman 7 downloads as the `alpm` user, so the temporary directory must be readable (755).
     *
     * @see https://man.archlinux.org/man/checkupdates.8
     */
    public const PACMAN_CHECK_COMMAND = "sh -c 'if command -v checkupdates >/dev/null 2>&1 && command -v fakeroot >/dev/null 2>&1; then checkupdates; rc=\$?; if [ \$rc -eq 2 ]; then exit 0; fi; exit \$rc; fi; db=\$(mktemp -d) || exit 1; chmod 755 \"\$db\"; ln -s /var/lib/pacman/local \"\$db/local\"; if ! LANG=C pacman -Sy --dbpath \"\$db\" --logfile /dev/null >/dev/null; then rm -rf \"\$db\"; exit 1; fi; pacman -Qu --dbpath \"\$db\" 2>/dev/null; rm -rf \"\$db\"; exit 0'";

    /**
     * Security advisories of the available updates. dnf5 still accepts `updateinfo` as an alias of `advisory`.
     *
     * @see https://dnf5.readthedocs.io/en/latest/commands/advisory.8.html
     */
    public const DNF_SECURITY_COMMAND = "sh -c 'LANG=C dnf updateinfo list --security -q 2>/dev/null; exit 0'";

    /**
     * Packages that the security patches would upgrade. `zypper -x list-patches` ignores --category, and it does
     * not contain package names, so a dry run of `zypper patch` is used.
     *
     * @see https://github.com/openSUSE/zypper/blob/master/doc/zypper.8.txt
     */
    public const ZYPPER_SECURITY_COMMAND = "sh -c 'LANG=C zypper -n -x patch --dry-run --category security 2>/dev/null; exit 0'";

    /**
     * Prints REBOOT=yes|no|unknown. Debian/Ubuntu write /var/run/reboot-required; needrestart batch mode reports the
     * kernel status (KSTA 2 and 3 mean a newer kernel is installed); dnf needs-restarting and zypper needs-rebooting
     * (exit code 102) cover the RHEL and SUSE families. Text is matched because a missing dnf plugin also exits 1.
     *
     * @see https://github.com/liske/needrestart/blob/master/README.batch.md
     * @see https://dnf-plugins-core.readthedocs.io/en/latest/needs_restarting.html
     */
    public const REBOOT_CHECK_COMMAND = <<<'SH'
sh -c 'if [ -f /var/run/reboot-required ]; then echo REBOOT=yes; exit 0; fi; if command -v needrestart >/dev/null 2>&1; then k=$(needrestart -b 2>/dev/null | sed -n "s/^NEEDRESTART-KSTA: *//p"); case "$k" in 2|3) echo REBOOT=yes; exit 0;; 1) echo REBOOT=no; exit 0;; esac; fi; if command -v dnf >/dev/null 2>&1; then o=$(LANG=C dnf needs-restarting -r 2>/dev/null); case "$o" in *"Reboot is required"*) echo REBOOT=yes; exit 0;; *"should not be necessary"*) echo REBOOT=no; exit 0;; esac; fi; if command -v zypper >/dev/null 2>&1; then zypper -q needs-rebooting >/dev/null 2>&1; rc=$?; if [ $rc -eq 102 ]; then echo REBOOT=yes; exit 0; fi; if [ $rc -eq 0 ]; then echo REBOOT=no; exit 0; fi; fi; if [ -d /var/lib/dpkg ]; then echo REBOOT=no; exit 0; fi; echo REBOOT=unknown'
SH;

    /**
     * Prints the running kernel (`RUNNING|<uname -r>`), each installed kernel (`KERNEL|<package>|<version>[|<arch>]`),
     * and the kernel that boots next (`BOOT|<uname -r>`, or `BOOT|newest`) when the boot loader tells it, so the
     * vulnerability list can tell an installed fix that waits for a reboot from a kernel that does not run.
     * The boot kernel comes from `grubby --default-kernel` (RHEL family), else from GRUB_DEFAULT and the saved entry
     * of GRUB: entry 0 is the newest kernel, a menu title or an entry ID contains the release. Other values print nothing.
     *
     * @see https://www.gnu.org/software/grub/manual/grub/html_node/Simple-configuration.html
     */
    public const KERNELS_COMMAND = <<<'SH'
sh -c 'echo "RUNNING|$(uname -r)"; if command -v dpkg-query >/dev/null 2>&1; then dpkg-query -W -f="\${db:Status-Abbrev}|\${Package}|\${Version}\n" "linux-image-[0-9]*" 2>/dev/null | sed -n "s/^ii *|/KERNEL|/p"; fi; if command -v rpm >/dev/null 2>&1; then rpm -q --qf "KERNEL|%{NAME}|%{VERSION}-%{RELEASE}|%{ARCH}\n" kernel-core kernel kernel-uek-core 2>/dev/null | grep "^KERNEL|"; fi; if command -v grubby >/dev/null 2>&1; then b=$(grubby --default-kernel 2>/dev/null); case "$b" in */vmlinuz-*) echo "BOOT|${b##*/vmlinuz-}";; esac; elif [ -f /etc/default/grub ]; then d=$(cat /etc/default/grub /etc/default/grub.d/*.cfg 2>/dev/null | sed -n "s/^[[:space:]]*GRUB_DEFAULT=//p" | tail -n 1 | tr -d "\042\047"); if [ "$d" = saved ]; then d=$(grub-editenv list 2>/dev/null | sed -n "s/^saved_entry=//p"); fi; case "$d" in ""|0) echo "BOOT|newest";; *) r=$(printf "%s\n" "$d" | sed -n -e "s/.*with Linux \([^ ]*\).*/\1/p" -e "s/.*gnulinux-\([0-9][^>]*\)-\(advanced\|recovery\)-.*/\1/p" | head -n 1); if [ -n "$r" ]; then echo "BOOT|$r"; fi;; esac; fi; exit 0'
SH;

    /**
     * Packages whose update can interrupt the server, per package manager. `*` matches like a shell glob.
     * docker: restarts the Docker daemon and with it the running containers. kernel: needs a reboot.
     * system: core libraries and services that are restarted or need a reboot to apply fully.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const RISKY_PACKAGES = [
        'apt' => [
            'docker' => ['docker-ce', 'docker-ce-cli', 'docker-ce-rootless-extras', 'docker-buildx-plugin', 'docker-compose-plugin', 'containerd.io', 'containerd', 'runc', 'docker.io'],
            'kernel' => ['linux-image-*', 'linux-modules-*', 'linux-generic*', 'linux-virtual*', 'linux-aws*', 'linux-azure*', 'linux-gcp*', 'linux-oracle*', 'linux-kvm*', 'linux-raspi*'],
            'system' => ['libc6', 'libc-bin', 'systemd', 'systemd-sysv', 'libsystemd0', 'udev', 'dbus', 'openssh-server', 'openssh-sftp-server'],
        ],
        'dnf' => [
            'docker' => ['docker-ce', 'docker-ce-cli', 'docker-ce-rootless-extras', 'docker-buildx-plugin', 'docker-compose-plugin', 'containerd.io', 'containerd', 'runc', 'moby-engine', 'docker'],
            'kernel' => ['kernel', 'kernel-core', 'kernel-modules', 'kernel-modules-core', 'kernel-modules-extra', 'kernel-uek', 'kernel-uek-core', 'kernel-uek-modules'],
            'system' => ['glibc', 'glibc-common', 'glibc-langpack-*', 'systemd', 'systemd-libs', 'systemd-udev', 'dbus', 'dbus-broker', 'openssh-server'],
        ],
        'zypper' => [
            'docker' => ['docker', 'docker-ce', 'containerd', 'runc', 'docker-compose', 'docker-buildx'],
            'kernel' => ['kernel-default', 'kernel-default-base', 'kernel-azure', 'kernel-kvmsmall'],
            'system' => ['glibc', 'systemd', 'libsystemd0', 'udev', 'dbus-1', 'openssh', 'openssh-server'],
        ],
        'pacman' => [
            'docker' => ['docker', 'containerd', 'runc', 'docker-compose', 'docker-buildx'],
            'kernel' => ['linux', 'linux-lts', 'linux-zen', 'linux-hardened'],
            'system' => ['glibc', 'systemd', 'systemd-libs', 'dbus', 'openssh'],
        ],
        'apk' => [
            'docker' => ['docker', 'docker-engine', 'docker-cli', 'docker-openrc', 'docker-cli-compose', 'docker-cli-buildx', 'containerd', 'runc'],
            'kernel' => ['linux-lts', 'linux-virt', 'linux-edge'],
            'system' => ['musl', 'openrc', 'openssh', 'openssh-server'],
        ],
    ];

    public const RISK_LABELS = [
        'docker' => 'Restarts Docker and the running containers',
        'kernel' => 'Needs a reboot to apply',
        'system' => 'Restarts core system services',
    ];

    public const HELD_REASON_LABELS = [
        'phased' => 'Phased rollout',
        'held' => 'Held',
    ];

    public function handle(Server $server)
    {
        $osId = 'unknown';
        $packageManager = null;

        try {
            if ($server->serverStatus() === false) {
                return [
                    'error' => 'Server is not reachable or not ready.',
                ];
            }

            // Try first method - using instant_remote_process
            $output = instant_remote_process(['cat /etc/os-release'], $server);

            // Parse os-release into an associative array
            $osInfo = [];
            foreach (explode("\n", $output) as $line) {
                if (empty($line)) {
                    continue;
                }
                if (strpos($line, '=') === false) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $osInfo[$key] = trim($value, '"');
            }

            // Get the main OS identifier
            $osId = $osInfo['ID'] ?? '';
            // $osIdLike = $osInfo['ID_LIKE'] ?? '';
            // $versionId = $osInfo['VERSION_ID'] ?? '';

            // Normalize OS types based on install.sh logic
            switch ($osId) {
                case 'manjaro':
                case 'manjaro-arm':
                case 'endeavouros':
                    $osType = 'arch';
                    break;
                case 'pop':
                case 'linuxmint':
                case 'zorin':
                    $osType = 'ubuntu';
                    break;
                case 'fedora-asahi-remix':
                    $osType = 'fedora';
                    break;
                default:
                    $osType = $osId;
            }

            // Determine package manager based on OS type
            $packageManager = match ($osType) {
                'arch' => 'pacman',
                'alpine' => 'apk',
                'ubuntu', 'debian', 'raspbian' => 'apt',
                'centos', 'fedora', 'rhel', 'ol', 'rocky', 'almalinux', 'amzn' => 'dnf',
                'sles', 'opensuse-leap', 'opensuse-tumbleweed' => 'zypper',
                default => null
            };

            $securityPackages = null;
            switch ($packageManager) {
                case 'zypper':
                    $out = $this->parseZypperOutput(instant_remote_process(['LANG=C zypper -tx list-updates'], $server));
                    $securityPackages = $this->parseZypperSecurityPackages((string) instant_remote_process([self::ZYPPER_SECURITY_COMMAND], $server, false));
                    break;
                case 'dnf':
                    $output = instant_remote_process([self::DNF_UPGRADES_COMMAND], $server);
                    $installed = instant_remote_process([self::RPM_INSTALLED_COMMAND], $server);
                    $out = $this->parseDnfOutput($output, $installed);
                    $securityPackages = $this->parseDnfSecurityPackages((string) instant_remote_process([self::DNF_SECURITY_COMMAND], $server, false));
                    break;
                case 'apt':
                    instant_remote_process(['apt-get update -qq'], $server);
                    $out = $this->separateHeldUpdates(
                        $this->parseAptOutput(instant_remote_process(['LANG=C apt list --upgradable 2>/dev/null'], $server)),
                        $this->parseAptHeldPackages(
                            (string) instant_remote_process([self::APT_HELD_COMMAND], $server, false),
                            (string) instant_remote_process([self::APT_SIMULATE_UPGRADE_COMMAND], $server, false),
                            (string) instant_remote_process([self::APT_SIMULATE_PHASED_UPGRADE_COMMAND], $server, false),
                        ),
                    );
                    break;
                case 'pacman':
                    $out = $this->parsePacmanOutput(instant_remote_process([self::PACMAN_CHECK_COMMAND], $server));
                    break;
                case 'apk':
                    instant_remote_process(['apk update -q'], $server);
                    $out = $this->parseApkOutput(instant_remote_process(['LANG=C apk list --upgradable 2>/dev/null'], $server));
                    $out = $this->separateHeldUpdates($out, $this->parseApkHeldPackages($out, (string) instant_remote_process([self::APK_SIMULATE_UPGRADE_COMMAND], $server, false)));
                    break;
                default:
                    return [
                        'osId' => $osId,
                        'error' => 'Unsupported package manager',
                        'package_manager' => $packageManager,
                    ];
            }

            $out = $this->addUpdateDetails($out, $packageManager, $securityPackages);
            $out['reboot_required'] = $this->parseRebootStatus((string) instant_remote_process([self::REBOOT_CHECK_COMMAND], $server, false));
            $out['kernels'] = $this->parseKernels((string) instant_remote_process([self::KERNELS_COMMAND], $server, false));
            $out['osId'] = $osId;
            $out['package_manager'] = $packageManager;

            return $out;
        } catch (\Throwable $e) {
            Log::warning('Server patch check failed', ['server_uuid' => $server->uuid, 'error' => $e->getMessage()]);

            return [
                'osId' => $osId,
                'package_manager' => $packageManager,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Adds the security flag and the risk to each update, and the number of security updates.
     * The security count is null when the package manager cannot tell (pacman, apk) or the advisory query failed.
     *
     * @param  list<string>|null  $securityPackages  Package names with a security advisory (dnf, zypper).
     */
    public function addUpdateDetails(array $out, string $packageManager, ?array $securityPackages = null): array
    {
        $knowsSecurity = $packageManager === 'apt' || $securityPackages !== null;

        $out['updates'] = array_map(function (array $update) use ($packageManager, $securityPackages) {
            $update['security'] = match ($packageManager) {
                'apt' => collect(explode(',', (string) ($update['repository'] ?? '')))->contains(fn (string $suite) => str_ends_with($suite, '-security')),
                default => in_array($update['package'], $securityPackages ?? [], true),
            };
            $update['risk'] = self::riskFor($packageManager, $update['package']);

            return $update;
        }, $out['updates'] ?? []);
        $out['security_updates'] = $knowsSecurity ? count(array_filter($out['updates'], fn (array $update) => $update['security'])) : null;

        return $out;
    }

    /**
     * @return 'docker'|'kernel'|'system'|null
     */
    public static function riskFor(?string $packageManager, string $package): ?string
    {
        foreach (self::RISKY_PACKAGES[$packageManager] ?? [] as $risk => $patterns) {
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $package)) {
                    return $risk;
                }
            }
        }

        return null;
    }

    /**
     * Updates of a saved check result that fix a vulnerable package: its own update, or for a kernel package that
     * has the version in its name (Debian, Ubuntu: linux-modules-6.8.0-139-generic) the kernel updates of the same
     * flavor, because the fixed kernel comes as a new package of the kernel meta packages. Empty on systems that only
     * do full upgrades.
     *
     * @param  array<string, mixed>|null  $patchResult
     * @return list<array<string, mixed>>
     */
    public static function fixingUpdates(string $package, ?array $patchResult): array
    {
        $packageManager = data_get($patchResult, 'package_manager');
        if (in_array($packageManager, self::FULL_UPGRADE_ONLY, true)) {
            return [];
        }
        $updates = collect(data_get($patchResult, 'updates', []));
        $ownUpdate = $updates->firstWhere('package', $package);
        if ($ownUpdate !== null) {
            return [$ownUpdate];
        }
        $flavor = self::kernelFlavor($package);
        if ($flavor === null || self::riskFor($packageManager, $package) !== 'kernel') {
            return [];
        }

        return $updates
            ->filter(fn ($update) => data_get($update, 'risk') === 'kernel' && self::kernelFlavor((string) data_get($update, 'package')) === $flavor)
            ->values()
            ->all();
    }

    /**
     * Flavor of a Debian or Ubuntu kernel package, so that an update of another flavor (linux-aws for a generic
     * kernel) is not offered as the fix: the suffix after the version (linux-modules-6.8.0-139-generic: generic),
     * or the meta package name without the part and the HWE/LTS suffix (linux-image-generic-hwe-24.04: generic).
     * Ubuntu's virtual meta packages install the generic kernel. Null when the name has no flavor.
     */
    private static function kernelFlavor(string $package): ?string
    {
        if (preg_match('/^linux-.*?-\d+\.\d+\.\d+[^-]*(?:-\d+)?-([a-z].*)$/', $package, $matches) === 1
            || preg_match('/^linux-(?:(?:signed-|unsigned-)?image-|headers-|modules-(?:extra-)?|tools-)?([a-z][a-z0-9-]*?)(?:-(?:hwe|lts)-[\d.]+(?:-edge)?)?$/', $package, $matches) === 1) {
            return $matches[1] === 'virtual' ? 'generic' : $matches[1];
        }

        return null;
    }

    /**
     * Package names from `dnf updateinfo list --security` (dnf4: "RLSA-2024:1234 Important/Sec. openssl-1:3.0.7-27.el9.x86_64",
     * dnf5: "FEDORA-2024-1 security Critical curl-8.6.0-1.fc40.x86_64 2024-01-01 00:00:00").
     *
     * @return list<string>
     */
    public function parseDnfSecurityPackages(string $output): array
    {
        $packages = [];
        foreach (preg_split('/\s+/', $output) as $token) {
            // name-[epoch:]version-release.arch: the name is everything before the last two dashes.
            if (preg_match('/^(.+)-[^-\/]+-[^-\/]+\.[A-Za-z0-9_]+$/', $token, $matches)) {
                $packages[] = $matches[1];
            }
        }

        return array_values(array_unique($packages));
    }

    /**
     * Package names that `zypper -x patch --dry-run --category security` would upgrade.
     *
     * @return list<string>
     */
    public function parseZypperSecurityPackages(string $output): array
    {
        if (! preg_match_all('#<to-upgrade>(.*?)</to-upgrade>#s', $output, $blocks)) {
            return [];
        }

        $packages = [];
        foreach ($blocks[1] as $block) {
            preg_match_all('#<solvable\b[^>]*>#', $block, $solvables);
            foreach ($solvables[0] as $solvable) {
                if (preg_match('#\bkind="package"#', $solvable) && preg_match('#\bname="([^"]+)"#', $solvable, $name)) {
                    $packages[] = $name[1];
                }
            }
        }

        return array_values(array_unique($packages));
    }

    /**
     * @param  string  $output  Output of KERNELS_COMMAND.
     * @return array{running: string, boot: ?string, installed: list<array{package: string, version: string, release: string}>}|null
     */
    public function parseKernels(string $output): ?array
    {
        $running = null;
        $boot = null;
        $installed = [];
        foreach (preg_split('/\R/', $output) as $line) {
            $parts = explode('|', trim($line));
            if ($parts[0] === 'RUNNING' && filled($parts[1] ?? null)) {
                $running = $parts[1];
            } elseif ($parts[0] === 'BOOT' && filled($parts[1] ?? null)) {
                $boot = $parts[1];
            } elseif ($parts[0] === 'KERNEL' && filled($parts[1] ?? null) && filled($parts[2] ?? null)) {
                $installed[] = [
                    'package' => $parts[1],
                    'version' => $parts[2],
                    // uname -r of this kernel: the package name without the prefix (dpkg), or version.arch (rpm).
                    'release' => filled($parts[3] ?? null) ? "{$parts[2]}.{$parts[3]}" : (string) preg_replace('/^linux-image-/', '', $parts[1]),
                ];
            }
        }

        if ($boot === 'newest') {
            $boot = collect($installed)->reduce(fn (?array $carry, array $kernel) => $carry === null || self::versionAtLeast($kernel['version'], $carry['version']) ? $kernel : $carry)['release'] ?? null;
        }

        return $running === null ? null : ['running' => $running, 'boot' => $boot, 'installed' => $installed];
    }

    /**
     * The installed kernel that the boot loader starts at the next reboot, or null when the last patch check
     * could not tell.
     *
     * @param  array<string, mixed>|null  $patchResult
     * @return array{package: string, version: string, release: string}|null
     */
    public static function bootKernel(?array $patchResult): ?array
    {
        $boot = data_get($patchResult, 'kernels.boot');

        return is_string($boot) ? collect(data_get($patchResult, 'kernels.installed', []))->firstWhere('release', $boot) : null;
    }

    /**
     * Kernel state of a vulnerable package, from the kernels of the last patch check:
     * - fixed_after_reboot: the package belongs to the running kernel, and the kernel that boots next has the fix.
     * - runs_after_reboot: the package belongs to the kernel that boots next, which is not the running one.
     * Both need the boot kernel (bootKernel()); without it, there is no claim. Other kernels that the server does
     * not run: see isOtherKernelPackage().
     *
     * @param  array<string, mixed>|null  $patchResult
     * @return 'fixed_after_reboot'|'runs_after_reboot'|null
     */
    public static function kernelStatus(string $package, string $version, ?string $fixedIn, ?array $patchResult): ?string
    {
        $running = data_get($patchResult, 'kernels.running');
        $installed = collect(data_get($patchResult, 'kernels.installed', []));
        if (! is_string($running) || $installed->isEmpty()) {
            return null;
        }
        $runningKernel = $installed->firstWhere('release', $running);
        $kernel = $installed->first(fn (array $kernel) => self::kernelHasPackage($kernel, $package, $version));
        if ($kernel === null || $runningKernel === null) {
            return null;
        }
        $bootKernel = self::bootKernel($patchResult);
        if ($bootKernel === null || $bootKernel['release'] === $running) {
            return null;
        }
        if ($kernel['release'] !== $running) {
            return $kernel['release'] === $bootKernel['release'] ? 'runs_after_reboot' : null;
        }

        return $fixedIn !== null && self::versionAtLeast($bootKernel['version'], $fixedIn) ? 'fixed_after_reboot' : null;
    }

    /**
     * True for a package of an installed kernel that the server does not run (`uname -r`), older or newer. Its
     * vulnerabilities are no risk now; a newer one runs after the next reboot and then replaces the running kernel. Debian kernel packages carry the release in the name
     * (linux-modules-6.8.0-139-generic, linux-headers-6.8.0-139), RPM kernel packages the version.
     */
    public static function isOtherKernelPackage(string $package, string $version, ?string $runningRelease): bool
    {
        if (blank($runningRelease)) {
            return false;
        }
        if (preg_match('/^linux-.*?-(\d+\.\d+(?:\.\d+)?-\d+)(?:-|$)/', $package, $packageKernel) === 1) {
            return preg_match('/^(\d+\.\d+(?:\.\d+)?-\d+)/', $runningRelease, $runningKernel) === 1
                && $packageKernel[1] !== $runningKernel[1];
        }

        if (! self::isRpmKernelPackage($package)) {
            return false;
        }
        $version = self::withoutEpoch($version);
        // SUSE: uname -r 6.4.0-150600.23.17-default for kernel-default 6.4.0-150600.23.17.1 (the last number is the rebuild).
        if (preg_match('/^(.+)-[a-z][a-z0-9_]*$/', $runningRelease, $suseRelease) === 1) {
            return (string) preg_replace('/\.\d+$/', '', $version) !== $suseRelease[1];
        }

        return $version !== (string) preg_replace('/\.[^.]+$/', '', $runningRelease);
    }

    /**
     * Packages of a bootable RPM kernel (dnf, zypper). Userspace packages such as kernel-tools or kernel-headers have
     * their own version and are not part of a kernel.
     */
    private static function isRpmKernelPackage(string $package): bool
    {
        return self::riskFor('dnf', $package) === 'kernel' || self::riskFor('zypper', $package) === 'kernel';
    }

    /**
     * Debian packages of a kernel have its release in the name (linux-modules-6.8.0-139-generic); RPM kernel packages
     * have a fixed name and the version of the kernel.
     *
     * @param  array{package: string, version: string, release: string}  $kernel
     */
    private static function kernelHasPackage(array $kernel, string $package, string $version): bool
    {
        if (str_ends_with($package, '-'.$kernel['release'])) {
            return true;
        }

        return str_starts_with($package, 'kernel') && self::withoutEpoch($version) === self::withoutEpoch($kernel['version']);
    }

    private static function versionAtLeast(string $version, string $minimum): bool
    {
        return version_compare(self::withoutEpoch($version), self::withoutEpoch($minimum), '>=');
    }

    private static function withoutEpoch(string $version): string
    {
        return (string) preg_replace('/^\d+:/', '', $version);
    }

    public function parseRebootStatus(string $output): ?bool
    {
        return match (true) {
            str_contains($output, 'REBOOT=yes') => true,
            str_contains($output, 'REBOOT=no') => false,
            default => null,
        };
    }

    public function parseZypperOutput(string $output): array
    {
        $updates = [];

        try {
            $xml = simplexml_load_string($output);
            if ($xml === false) {
                return [
                    'total_updates' => 0,
                    'updates' => [],
                    'error' => 'Failed to parse XML output',
                ];
            }

            // Navigate to the update-list node
            $updateList = $xml->xpath('//update-list/update');

            foreach ($updateList as $update) {
                $updates[] = [
                    'package' => (string) $update['name'],
                    'new_version' => (string) $update['edition'],
                    'current_version' => (string) $update['edition-old'],
                    'architecture' => (string) $update['arch'],
                    'repository' => (string) $update->source['alias'],
                    'summary' => (string) $update->summary,
                    'description' => (string) $update->description,
                ];
            }

            return [
                'total_updates' => count($updates),
                'updates' => $updates,
            ];
        } catch (\Throwable $e) {
            return [
                'total_updates' => 0,
                'updates' => [],
                'error' => 'Error parsing zypper output: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @param  string  $output  Output of DNF_UPGRADES_COMMAND: "name arch evr repoid" per line.
     * @param  string  $installed  Output of RPM_INSTALLED_COMMAND: "name arch version-release" per line.
     */
    public function parseDnfOutput(string $output, string $installed = ''): array
    {
        $installedVersions = [];
        foreach (explode("\n", $installed) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) === 3) {
                $installedVersions["{$parts[0]}.{$parts[1]}"] = $parts[2];
            }
        }

        $updates = [];
        foreach (explode("\n", $output) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) !== 4) {
                continue;
            }
            [$package, $architecture, $newVersion, $repository] = $parts;

            $updates["{$package}.{$architecture}"] = [
                'package' => $package,
                'new_version' => preg_replace('/^0:/', '', $newVersion),
                'repository' => $repository,
                'architecture' => $architecture,
                'current_version' => $installedVersions["{$package}.{$architecture}"] ?? 'unknown',
            ];
        }
        $updates = array_values($updates);

        return [
            'total_updates' => count($updates),
            'updates' => $updates,
        ];
    }

    public function parseAptOutput(string $output): array
    {
        $updates = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            // Skip the "Listing... Done" line and empty lines
            if (empty($line) || str_contains($line, 'Listing...')) {
                continue;
            }

            // Example line: package/stable 2.0-1 amd64 [upgradable from: 1.0-1]
            if (preg_match('/^(.+?)\/(\S+)\s+(\S+)\s+(\S+)\s+\[upgradable from: (.+?)\]/', $line, $matches)) {
                $updates[] = [
                    'package' => $matches[1],
                    'repository' => $matches[2],
                    'new_version' => $matches[3],
                    'architecture' => $matches[4],
                    'current_version' => $matches[5],
                ];
            }
        }

        return [
            'total_updates' => count($updates),
            'updates' => $updates,
        ];
    }

    /**
     * Moves the updates that the package manager does not install to `held_updates`, with the reason in `held_reason`.
     *
     * @param  array<string, 'phased'|'held'>  $heldPackages  Package name => reason.
     */
    public function separateHeldUpdates(array $out, array $heldPackages): array
    {
        $updates = collect($out['updates'] ?? [])->partition(fn (array $update) => isset($heldPackages[$update['package']]));
        $out['updates'] = $updates[1]->values()->all();
        $out['held_updates'] = $updates[0]->map(fn (array $update) => [...$update, 'held_reason' => $heldPackages[$update['package']]])->values()->all();
        $out['total_updates'] = count($out['updates']);

        return $out;
    }

    /**
     * @param  string  $held  Output of APT_HELD_COMMAND.
     * @param  string  $upgrade  Output of APT_SIMULATE_UPGRADE_COMMAND.
     * @param  string  $upgradeWithPhased  Output of APT_SIMULATE_PHASED_UPGRADE_COMMAND.
     * @return array<string, 'phased'|'held'>
     */
    public function parseAptHeldPackages(string $held, string $upgrade, string $upgradeWithPhased): array
    {
        $installs = function (string $output): ?array {
            // Without the summary line, the simulation failed and tells nothing.
            if (! preg_match('/^\d+ upgraded,/m', $output)) {
                return null;
            }
            preg_match_all('/^Inst ([^\s:]+)/m', $output, $matches);

            return $matches[1];
        };
        $installed = $installs($upgrade);
        $installedWithPhased = $installs($upgradeWithPhased);
        $phased = $installed === null || $installedWithPhased === null ? [] : array_diff($installedWithPhased, $installed);
        preg_match_all('/^([^\s:]+)/m', $held, $heldMatches);

        return [...array_fill_keys($phased, 'phased'), ...array_fill_keys($heldMatches[1], 'held')];
    }

    /**
     * @param  string  $simulation  Output of APK_SIMULATE_UPGRADE_COMMAND.
     * @return array<string, 'held'>
     */
    public function parseApkHeldPackages(array $out, string $simulation): array
    {
        // Without the summary line, the simulation failed and tells nothing.
        if (! preg_match('/^OK: /m', $simulation)) {
            return [];
        }
        preg_match_all('/^\(\s*\d+\/\d+\) Upgrading (\S+) \(/m', $simulation, $matches);
        $held = array_diff(array_column($out['updates'] ?? [], 'package'), $matches[1]);

        return array_fill_keys($held, 'held');
    }

    public function parsePacmanOutput(string $output): array
    {
        $updates = [];
        $unparsedLines = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }
            // Format: package current_version -> new_version
            if (preg_match('/^(\S+)\s+(\S+)\s+->\s+(\S+)$/', $line, $matches)) {
                $updates[] = [
                    'package' => $matches[1],
                    'current_version' => $matches[2],
                    'new_version' => $matches[3],
                    'architecture' => 'unknown',
                    'repository' => 'unknown',
                ];
            } else {
                // Log unmatched lines for debugging purposes
                $unparsedLines[] = $line;
            }
        }

        $result = [
            'total_updates' => count($updates),
            'updates' => $updates,
        ];

        // Include unparsed lines in the result for debugging if any exist
        if (! empty($unparsedLines)) {
            $result['unparsed_lines'] = $unparsedLines;
            Log::debug('Pacman output contained unparsed lines', [
                'unparsed_lines' => $unparsedLines,
            ]);
        }

        return $result;
    }

    public function parseApkOutput(string $output): array
    {
        $updates = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            // Skip empty lines
            if (empty($line)) {
                continue;
            }

            // Example line: docker-cli-compose-2.31.0-r5 x86_64 {docker-cli-compose} (Apache-2.0) [upgradable from: docker-cli-compose-2.31.0-r4]
            if (preg_match('/^(.+)-([0-9]\S*) (\S+) \{\S+\} \([^)]+\) \[upgradable from: .+?-([0-9][^\]]+)\]$/', $line, $matches)) {
                $updates[] = [
                    'package' => $matches[1],
                    'new_version' => $matches[2],
                    'architecture' => $matches[3],
                    'current_version' => $matches[4],
                ];
            }
        }

        return [
            'total_updates' => count($updates),
            'updates' => $updates,
        ];
    }
}
