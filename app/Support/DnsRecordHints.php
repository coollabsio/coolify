<?php

namespace App\Support;

/**
 * Provider-agnostic DNS records to point hostnames at a Coolify server.
 */
class DnsRecordHints
{
    public const NO_PUBLIC_ADDRESS_MESSAGE = 'The server has no public IP address; add the DNS record manually.';

    /**
     * Whether an address may be published as a public A/AAAA record: private, reserved, loopback, link-local,
     * unique local, CGNAT (100.64.0.0/10, used by Tailscale), and multicast addresses are rejected.
     */
    public static function isPublicAddress(string $address): bool
    {
        $binary = @inet_pton($address);
        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            $address = (string) inet_ntop(substr($binary, 12));
            $binary = substr($binary, 12);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (strlen($binary) === 4) {
            $firstOctet = ord($binary[0]);
            $isCgnat = $firstOctet === 100 && (ord($binary[1]) & 0xC0) === 64;

            return ! $isCgnat && ($firstOctet & 0xF0) !== 224;
        }

        return ord($binary[0]) !== 0xFF;
    }

    /**
     * Whether two DNS record values name the same address. IP addresses are compared in binary form,
     * so equivalent IPv6 notations (2001:db8::1 and 2001:0DB8:0:0::1) match; other values must be identical.
     */
    public static function sameAddress(?string $first, ?string $second): bool
    {
        if ($first === null || $second === null) {
            return false;
        }

        $firstBinary = @inet_pton(trim($first));
        $secondBinary = @inet_pton(trim($second));
        if ($firstBinary !== false && $secondBinary !== false) {
            return $firstBinary === $secondBinary;
        }

        return $first === $second;
    }

    /**
     * Build A/AAAA entries for every hostname (deduped).
     *
     * @param  array<int, string|null>  $hostnames
     * @return array<int, array{type: string, name: string, value: string}>
     */
    public static function forHostnames(array $hostnames, ?string $ipv4, ?string $ipv6 = null): array
    {
        $records = [];
        $seen = [];

        foreach ($hostnames as $hostname) {
            if (! is_string($hostname) || trim($hostname) === '') {
                continue;
            }

            foreach (self::forTarget($hostname, $ipv4, $ipv6) as $record) {
                $key = strtolower($record['type'].'|'.$record['name'].'|'.$record['value']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $records[] = $record;
            }
        }

        usort($records, function (array $a, array $b): int {
            return [$a['name'], $a['type'], $a['value']] <=> [$b['name'], $b['type'], $b['value']];
        });

        return $records;
    }

    /**
     * @return array<int, array{type: string, name: string, value: string}>
     */
    public static function forTarget(?string $hostname, ?string $ipv4, ?string $ipv6 = null): array
    {
        $records = [];
        $fqdn = self::normalizeHostname($hostname);
        if ($fqdn === null) {
            return [];
        }

        if (filled($ipv4) && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $records[] = [
                'type' => 'A',
                'name' => $fqdn,
                'value' => $ipv4,
            ];
        }

        if (filled($ipv6) && filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $records[] = [
                'type' => 'AAAA',
                'name' => $fqdn,
                'value' => $ipv6,
            ];
        }

        return $records;
    }

    public static function normalizeHostname(?string $hostname): ?string
    {
        if (blank($hostname)) {
            return null;
        }

        $hostname = strtolower(trim($hostname));
        $hostname = preg_replace('#^https?://#i', '', $hostname) ?? $hostname;
        $hostname = explode('/', $hostname)[0] ?? $hostname;
        $hostname = explode(':', $hostname)[0] ?? $hostname;
        $hostname = rtrim($hostname, '.');

        if ($hostname === '' || $hostname === '@' || ! str_contains($hostname, '.')) {
            return null;
        }

        // Strip path-like noise; host only.
        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            return null;
        }

        return $hostname;
    }

    /**
     * Relative name for a zone (e.g. app for app.example.com, @ for example.com).
     */
    public static function relativeName(?string $hostname): string
    {
        $fqdn = self::normalizeHostname($hostname);
        if ($fqdn === null) {
            return '@';
        }

        $labels = array_values(array_filter(explode('.', $fqdn), fn (string $p) => $p !== ''));
        if (count($labels) <= 2) {
            return '@';
        }

        return implode('.', array_slice($labels, 0, -2));
    }

    /**
     * BIND-compatible zone snippet for clipboard (absolute names with trailing dots).
     *
     * Example:
     * asd.hu.     IN A 172.16.0.2
     * www.asd.hu. IN A 172.16.0.2
     *
     * @param  array<int, array{type: string, name: string, value: string}>  $records
     */
    public static function toCopyText(array $records): string
    {
        if ($records === []) {
            return '';
        }

        $lines = [];
        $nameWidth = 0;

        foreach ($records as $record) {
            $name = self::bindAbsoluteName((string) $record['name']);
            $nameWidth = max($nameWidth, strlen($name));
        }

        foreach ($records as $record) {
            $name = self::bindAbsoluteName((string) $record['name']);
            $type = strtoupper((string) $record['type']);
            $value = (string) $record['value'];
            // AAAA values may be IPv6; leave as-is (no quotes needed for A/AAAA).
            $format = '%-'.$nameWidth.'s  IN %-5s %s';
            $lines[] = sprintf($format, $name, $type, $value);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Absolute BIND name (trailing dot). Leaves @ as-is.
     */
    public static function bindAbsoluteName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '@') {
            return '@';
        }

        $name = rtrim($name, '.');

        return $name.'.';
    }
}
