<?php

namespace App\Support;

/**
 * Provider-agnostic DNS records to point hostnames at a Coolify server.
 */
class DnsRecordHints
{
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
