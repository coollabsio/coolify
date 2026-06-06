<?php

namespace App\Support\Networking;

class Cidr
{
    /**
     * @return array{start: int, end: int}|null
     */
    public static function range(string $cidr): ?array
    {
        [$ip, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);

        if (! self::isValidIp($ip) || $prefix === null || ! ctype_digit($prefix)) {
            return null;
        }

        $prefix = (int) $prefix;

        if ($prefix < 0 || $prefix > 32) {
            return null;
        }

        $ipLong = self::ipToUnsignedInt($ip);
        $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        $start = $ipLong & $mask;
        $end = $start | (~$mask & 0xFFFFFFFF);

        return ['start' => $start, 'end' => $end];
    }

    public static function isValid(string $cidr): bool
    {
        return self::range($cidr) !== null;
    }

    public static function containsIp(string $cidr, string $ip): bool
    {
        $range = self::range($cidr);

        if ($range === null || ! self::isValidIp($ip)) {
            return false;
        }

        $ipLong = self::ipToUnsignedInt($ip);

        return $ipLong >= $range['start'] && $ipLong <= $range['end'];
    }

    public static function overlaps(string $first, string $second): bool
    {
        $firstRange = self::range($first);
        $secondRange = self::range($second);

        if ($firstRange === null || $secondRange === null) {
            return false;
        }

        return $firstRange['start'] <= $secondRange['end']
            && $secondRange['start'] <= $firstRange['end'];
    }

    private static function isValidIp(?string $ip): bool
    {
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private static function ipToUnsignedInt(string $ip): int
    {
        return (int) sprintf('%u', ip2long($ip));
    }
}
