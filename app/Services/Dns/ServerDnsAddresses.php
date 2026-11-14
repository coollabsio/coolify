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
     * @param  bool  $isInstanceServer  The server that runs Coolify: the instance public IPv4/IPv6 settings also apply.
     */
    public function __construct(
        public readonly ?string $address,
        public readonly ?string $configured,
        public readonly bool $isInstanceServer,
    ) {}

    public static function forServer(?Server $server): self
    {
        if ($server === null) {
            return new self(null, null, false);
        }

        $settings = instanceSettings();
        $configured = $server->id === 0
            ? (data_get($settings, 'public_ipv4') ?: data_get($settings, 'public_ipv6') ?: $server->ip)
            : $server->ip;
        $resolved = resolveServerIpAddress(is_string($configured) ? $configured : null);

        return new self($resolved['ip'] ?? $resolved['configured'], $resolved['configured'], $server->id === 0);
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

        if ($this->isInstanceServer) {
            try {
                $settings = instanceSettings();
                $publicV4 = data_get($settings, 'public_ipv4');
                $publicV6 = data_get($settings, 'public_ipv6');
                if ($ipv4 === null && is_string($publicV4) && filter_var($publicV4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ipv4 = $publicV4;
                }
                if ($ipv6 === null && is_string($publicV6) && filter_var($publicV6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $ipv6 = $publicV6;
                }
            } catch (\Throwable) {
                //
            }
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
