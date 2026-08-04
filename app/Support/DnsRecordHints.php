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
     * Plain-text block suitable for clipboard (type / name / value).
     *
     * @param  array<int, array{type: string, name: string, value: string}>  $records
     */
    public static function toCopyText(array $records): string
    {
        if ($records === []) {
            return '';
        }

        $lines = ["Type\tName\tValue"];
        foreach ($records as $record) {
            $lines[] = "{$record['type']}\t{$record['name']}\t{$record['value']}";
        }

        return implode("\n", $lines);
    }
}
