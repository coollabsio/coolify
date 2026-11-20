<?php

namespace App\Services\Dns;

use App\Models\Server;
use App\Support\DnsRecordHints;

/**
 * Addresses of a server that DNS records of its resources point to. The Domains pages and the DNS API use the same rules.
 */
final class ServerDnsAddresses
{
    /**
     * @param  string|null  $address  The resolved IP, or the configured value when it does not resolve.
     * @param  string|null  $configured  The address as configured (can be a hostname).
     * @param  string|null  $publicIpv4  The IPv4 for DNS records (see ipsByFamily()).
     * @param  string|null  $publicIpv6  The IPv6 for DNS records (see ipsByFamily()).
     */
    public function __construct(
        public readonly ?string $address,
        public readonly ?string $configured,
        public readonly ?string $publicIpv4 = null,
        public readonly ?string $publicIpv6 = null,
    ) {}

    /**
     * The public IPs of a server win over its SSH address: Coolify can connect to a server over a private network
     * while DNS records point to its public IP.
     */
    public static function forServer(?Server $server): self
    {
        if ($server === null) {
            return new self(null, null);
        }

        [$publicIpv4, $publicIpv6] = self::publicIps($server);
        $configured = $publicIpv4 ?? $publicIpv6 ?? $server->ip;
        $resolved = resolveServerIpAddress(is_string($configured) ? $configured : null);

        return new self($resolved['ip'] ?? $resolved['configured'], $resolved['configured'], ...self::ipsByFamily($server));
    }

    /**
     * The public IPv4 and IPv6 set for a server. The server that runs Coolify uses the instance settings.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function publicIps(?Server $server): array
    {
        $settings = $server?->id === 0 ? instanceSettings() : $server?->settings;

        return [data_get($settings, 'public_ipv4') ?: null, data_get($settings, 'public_ipv6') ?: null];
    }

    /**
     * The IPv4 and IPv6 for DNS records of a server: the public IP of each family, or else the server IP when it is
     * of that family. A public IPv6 alone must not hide the IPv4 of the server, and the other way around.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function ipsByFamily(?Server $server): array
    {
        [$ipv4, $ipv6] = self::publicIps($server);
        if ($server === null || ($ipv4 !== null && $ipv6 !== null)) {
            return [$ipv4, $ipv6];
        }

        $serverIp = resolveServerIpAddress(is_string($server->ip) ? $server->ip : null)['ip'];
        if (is_string($serverIp)) {
            $ipv4 ??= filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $serverIp : null;
            $ipv6 ??= filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? $serverIp : null;
        }

        return [$ipv4, $ipv6];
    }

    /**
     * @return array{0: ?string, 1: ?string} IPv4 and IPv6 address
     */
    public function addresses(): array
    {
        $ipv4 = null;
        $ipv6 = null;

        if (filled($this->address)) {
            if (filter_var($this->address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ipv4 = $this->address;
            } elseif (filter_var($this->address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $ipv6 = $this->address;
            }
        }

        if ($ipv4 === null && is_string($this->publicIpv4) && filter_var($this->publicIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipv4 = $this->publicIpv4;
        }
        if ($ipv6 === null && is_string($this->publicIpv6) && filter_var($this->publicIpv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipv6 = $this->publicIpv6;
        }

        return [$ipv4, $ipv6];
    }

    /**
     * Addresses that a DNS provider may publish (IPv4 first). Private, reserved, CGNAT, and link-local addresses are
     * excluded: a public DNS record must not expose them, and the record would not be reachable anyway.
     *
     * @return array<int, string>
     */
    public function publicAddresses(): array
    {
        return array_values(array_filter(
            $this->addresses(),
            fn (?string $address): bool => is_string($address) && DnsRecordHints::isPublicAddress($address),
        ));
    }

    /**
     * Content of a DNS record: one of the public addresses, the first one when none is requested.
     * Any other address is rejected (null).
     */
    public function recordContent(?string $requested = null): ?string
    {
        $addresses = $this->publicAddresses();

        if ($requested === null) {
            return $addresses[0] ?? null;
        }

        return collect($addresses)->first(fn (string $address): bool => DnsRecordHints::sameAddress($address, $requested));
    }
}
