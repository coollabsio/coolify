<?php

namespace App\Actions\Server;

use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Turns automatic security updates on or off on a server.
 *
 * apt (Debian, Ubuntu): unattended-upgrades with a Coolify file that allows only the security origins.
 * `#clear` drops the origin lists of the distro files (apt.conf(5),
 * https://manpages.debian.org/bookworm/apt/apt.conf.5.en.html); the origin patterns follow
 * https://github.com/mvo5/unattended-upgrades/blob/master/README.md. The Debian security suite keeps
 * the `<codename>-security` codename (for Debian 12 the suite is now `oldstable-security`). The Ubuntu Pro (ESM)
 * security origins are kept, as in the Ubuntu default file.
 * The time is set with a drop-in for `apt-daily-upgrade.timer`.
 *
 * dnf (RHEL family, Fedora): dnf-automatic with `upgrade_type = security` and `apply_updates = yes` in
 * `/etc/dnf/automatic.conf`. dnf4 reads only that file (https://dnf.readthedocs.io/en/latest/automatic.html);
 * dnf5 reads its defaults from /usr/share/dnf5/dnf5-plugins/automatic.conf and the overrides from that file
 * (https://dnf5.readthedocs.io/en/latest/dnf5_plugins/automatic.8.html). The timer is `dnf5-automatic.timer`
 * on dnf5 and `dnf-automatic.timer` on dnf4.
 *
 * Commands are written for non-root SSH users: files are written with `tee`, and multi-step logic is one
 * `sh -c` script, so `parseCommandsByLineForSudo()` only puts sudo in front.
 */
class ConfigureAutomaticSecurityUpdates
{
    use AsAction;

    public const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public const APT_CONFIG_FILE = '/etc/apt/apt.conf.d/52coolify-unattended-upgrades';

    public const APT_TIMER_DROP_IN_DIRECTORY = '/etc/systemd/system/apt-daily-upgrade.timer.d';

    public const DNF_CONFIG_FILE = '/etc/dnf/automatic.conf';

    private const SUPPORTED_PACKAGE_MANAGERS = ['apt', 'dnf'];

    public function handle(Server $server, string $packageManager, bool $enabled, string $time): void
    {
        if (preg_match(self::TIME_PATTERN, $time) !== 1) {
            throw new InvalidArgumentException('The time must be in HH:MM format (00:00 to 23:59).');
        }
        if (! self::supportFor($packageManager)) {
            throw new RuntimeException("Automatic security updates are not supported for the package manager {$packageManager}.");
        }

        instant_remote_process(self::commands($packageManager, $enabled, $time), $server);
    }

    public static function supportFor(?string $packageManager): bool
    {
        return in_array($packageManager, self::SUPPORTED_PACKAGE_MANAGERS, true);
    }

    /**
     * @return list<string>
     */
    public static function commands(string $packageManager, bool $enabled, string $time): array
    {
        return match ($packageManager) {
            'apt' => $enabled ? self::aptEnableCommands($time) : self::aptDisableCommands(),
            'dnf' => $enabled ? self::dnfEnableCommands($time) : self::dnfDisableCommands(),
            default => throw new RuntimeException("Automatic security updates are not supported for the package manager {$packageManager}."),
        };
    }

    public static function aptConfig(): string
    {
        return <<<'CONF'
            // Managed by Coolify: install security updates only.
            APT::Periodic::Update-Package-Lists "1";
            APT::Periodic::Unattended-Upgrade "1";
            #clear Unattended-Upgrade::Allowed-Origins;
            #clear Unattended-Upgrade::Origins-Pattern;
            Unattended-Upgrade::Origins-Pattern {
                "origin=Debian,codename=${distro_codename}-security,label=Debian-Security";
                "origin=Debian,codename=${distro_codename},label=Debian-Security";
                "origin=Ubuntu,archive=${distro_codename}-security";
                "origin=UbuntuESMApps,archive=${distro_codename}-apps-security";
                "origin=UbuntuESM,archive=${distro_codename}-infra-security";
            };

            CONF;
    }

    public static function timerDropIn(string $time): string
    {
        return "[Timer]\nOnCalendar=\nOnCalendar=*-*-* {$time}\nRandomizedDelaySec=0\n";
    }

    /**
     * @return list<string>
     */
    private static function aptEnableCommands(string $time): array
    {
        $dropIn = self::APT_TIMER_DROP_IN_DIRECTORY.'/coolify.conf';

        return [
            "sh -c 'command -v unattended-upgrade >/dev/null 2>&1 || { apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y unattended-upgrades; }'",
            'mkdir -p '.self::APT_TIMER_DROP_IN_DIRECTORY,
            "echo '".base64_encode(self::aptConfig())."' | base64 -d | tee ".self::APT_CONFIG_FILE.' > /dev/null',
            "echo '".base64_encode(self::timerDropIn($time))."' | base64 -d | tee {$dropIn} > /dev/null",
            'chmod 644 '.self::APT_CONFIG_FILE." {$dropIn}",
            'systemctl daemon-reload',
            'systemctl enable --now apt-daily.timer apt-daily-upgrade.timer',
        ];
    }

    /**
     * @return list<string>
     */
    private static function aptDisableCommands(): array
    {
        return [
            'rm -f '.self::APT_CONFIG_FILE.' '.self::APT_TIMER_DROP_IN_DIRECTORY.'/coolify.conf',
            'systemctl daemon-reload',
        ];
    }

    /**
     * The settings are removed wherever they are and written again right below `[commands]`, which works for
     * the full dnf4 file and for a missing or short dnf5 override file.
     *
     * @return list<string>
     */
    private static function dnfEnableCommands(string $time): array
    {
        $file = self::DNF_CONFIG_FILE;
        $dropIn = base64_encode(self::timerDropIn($time));

        return [
            'dnf install -y dnf-automatic',
            "sh -c 'set -e; f={$file}; touch \$f; "
                .'sed -i -E "/^[[:space:]]*(upgrade_type|apply_updates)[[:space:]]*=/d" $f; '
                .'grep -q "^\[commands\]" $f || printf "\n[commands]\n" >> $f; '
                .'sed -i -e "/^\[commands\]/a upgrade_type = security" -e "/^\[commands\]/a apply_updates = yes" $f; '
                ."chmod 644 \$f'",
            "sh -c 'set -e; ".self::dnfTimerSelection()
                .'mkdir -p /etc/systemd/system/$t.d; '
                ."echo {$dropIn} | base64 -d > /etc/systemd/system/\$t.d/coolify.conf; "
                .'chmod 644 /etc/systemd/system/$t.d/coolify.conf; '
                ."systemctl daemon-reload; systemctl enable --now \$t'",
        ];
    }

    /**
     * @return list<string>
     */
    private static function dnfDisableCommands(): array
    {
        return [
            "sh -c 'set -e; ".self::dnfTimerSelection()
                .'if [ -e /usr/lib/systemd/system/$t ]; then systemctl disable --now $t; fi; '
                ."rm -f /etc/systemd/system/\$t.d/coolify.conf; systemctl daemon-reload'",
        ];
    }

    /**
     * Sets `$t` to the timer unit of the installed dnf-automatic (dnf5 or dnf4).
     */
    private static function dnfTimerSelection(): string
    {
        return 't=dnf-automatic.timer; if [ -e /usr/lib/systemd/system/dnf5-automatic.timer ]; then t=dnf5-automatic.timer; fi; ';
    }
}
