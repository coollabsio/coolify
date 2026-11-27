<?php

namespace App\Support;

use App\Models\User;
use RuntimeException;

/**
 * The allow list of the isolation options of a container (decision 0036): `--cap-add` and
 * `--sysctl` in three tiers. Sentinel has the same list (`crates/control/src/isolation.rs`) and
 * refuses tier 3. Sentinel does not know the role of the user, so Coolify checks tier 2.
 *
 * Decision 0032 uses it for cluster applications. Decision 0036 uses it for stacks.
 */
final class ContainerIsolation
{
    /** Tier 1: the capabilities of the Podman default set. Every user who can change the workload. */
    public const TIER1_CAP_ADD = ['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'FSETID', 'KILL', 'NET_BIND_SERVICE', 'SETFCAP', 'SETGID', 'SETPCAP', 'SETUID', 'SYS_CHROOT'];

    /** Tier 2: a team admin or owner only. */
    public const TIER2_CAP_ADD = ['NET_ADMIN', 'NET_RAW', 'SYS_PTRACE', 'SYS_NICE', 'SYS_RESOURCE', 'IPC_LOCK'];

    public const TIER1_SYSCTLS = [
        'net.core.somaxconn', 'net.ipv4.ip_local_port_range', 'net.ipv4.tcp_syncookies', 'net.ipv4.ping_group_range',
        'net.ipv4.ip_unprivileged_port_start', 'net.ipv4.tcp_keepalive_time', 'net.ipv4.tcp_keepalive_intvl',
        'net.ipv4.tcp_keepalive_probes', 'net.ipv4.tcp_fin_timeout', 'kernel.shm_rmid_forced',
    ];

    public const TIER2_SYSCTLS = ['net.ipv4.ip_forward'];

    /** Tier 2 sysctls of an interface: `all`, `default`, or an interface name. */
    public const TIER2_INTERFACE_SYSCTL_PATTERN = '/\Anet\.ipv4\.conf\.(all|default|[a-z0-9]{1,15})\.(src_valid_mark|forwarding)\z|\Anet\.ipv6\.conf\.(all|default|[a-z0-9]{1,15})\.forwarding\z/';

    /** The value rule of Sentinel: one or two numbers. */
    public const SYSCTL_VALUE_PATTERN = '/\A[0-9]{1,10}( [0-9]{1,10})?\z/';

    /** The capabilities of the Linux kernel, for `--cap-drop`. */
    public const LINUX_CAPABILITIES = [
        'AUDIT_CONTROL', 'AUDIT_READ', 'AUDIT_WRITE', 'BLOCK_SUSPEND', 'BPF', 'CHECKPOINT_RESTORE', 'CHOWN',
        'DAC_OVERRIDE', 'DAC_READ_SEARCH', 'FOWNER', 'FSETID', 'IPC_LOCK', 'IPC_OWNER', 'KILL', 'LEASE',
        'LINUX_IMMUTABLE', 'MAC_ADMIN', 'MAC_OVERRIDE', 'MKNOD', 'NET_ADMIN', 'NET_BIND_SERVICE', 'NET_BROADCAST',
        'NET_RAW', 'PERFMON', 'SETFCAP', 'SETGID', 'SETPCAP', 'SETUID', 'SYSLOG', 'SYS_ADMIN', 'SYS_BOOT',
        'SYS_CHROOT', 'SYS_MODULE', 'SYS_NICE', 'SYS_PACCT', 'SYS_PTRACE', 'SYS_RAWIO', 'SYS_RESOURCE', 'SYS_TIME',
        'SYS_TTY_CONFIG', 'WAKE_ALARM',
    ];

    /** A capability name without `CAP_`, in upper case. */
    public static function normalizeCapability(string $name): string
    {
        $name = strtoupper(trim($name));

        return str_starts_with($name, 'CAP_') ? substr($name, 4) : $name;
    }

    /** 1, 2, or 3 (refused for every user). */
    public static function capabilityTier(string $name): int
    {
        $name = self::normalizeCapability($name);

        return match (true) {
            in_array($name, self::TIER1_CAP_ADD, true) => 1,
            in_array($name, self::TIER2_CAP_ADD, true) => 2,
            default => 3,
        };
    }

    /** 1, 2, or 3 (refused for every user). */
    public static function sysctlTier(string $name): int
    {
        return match (true) {
            in_array($name, self::TIER1_SYSCTLS, true) => 1,
            in_array($name, self::TIER2_SYSCTLS, true), preg_match(self::TIER2_INTERFACE_SYSCTL_PATTERN, $name) === 1 => 2,
            default => 3,
        };
    }

    /**
     * The values of tier 2 in typed runtime options.
     *
     * @param  array{cap_add?: list<string>, sysctls?: list<array{name: string, value: string}>}  $runtimeOptions
     * @return list<string> For example `--cap-add NET_ADMIN`.
     */
    public static function adminTierValues(array $runtimeOptions): array
    {
        $values = [];
        foreach ($runtimeOptions['cap_add'] ?? [] as $capability) {
            if (self::capabilityTier($capability) === 2) {
                $values[] = "--cap-add {$capability}";
            }
        }
        foreach ($runtimeOptions['sysctls'] ?? [] as $sysctl) {
            if (self::sysctlTier($sysctl['name']) === 2) {
                $values[] = "--sysctl {$sysctl['name']}";
            }
        }

        return $values;
    }

    /**
     * Tier 2 needs a team admin or owner of the team of the resource, like a mount of the Docker
     * socket. A change without a user (a webhook, the reconciler) is allowed.
     *
     * @param  array<string, mixed>  $runtimeOptions
     */
    public static function ensureMayUseAdminTier(?User $user, int $teamId, array $runtimeOptions, string $subject = 'This application'): void
    {
        $values = self::adminTierValues($runtimeOptions);
        if ($values !== [] && $user !== null && ! $user->isAdminOfTeam($teamId)) {
            throw new RuntimeException("{$subject} uses ".implode(', ', $values).', which only a team admin or owner can set, change, or deploy. They give the container more control of the network or the processes of its server.');
        }
    }
}
