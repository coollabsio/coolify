<?php

namespace App\Support;

/**
 * Builds the favicon URL that the browser loads for a domain row.
 *
 * Domains on a private network get no favicon: loading it from a public Coolify page triggers the
 * Chrome "access other devices on your local network" permission prompt.
 */
class DomainFavicon
{
    /** @var list<string> */
    private const LOCAL_SUFFIXES = ['.localhost', '.local', '.lan', '.internal', '.home.arpa', '.test'];

    public static function url(string $publicUrl, ?string $dnsStatus = null, ?string $expectedIp = null): ?string
    {
        $parts = parse_url($publicUrl);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (self::isPrivateHost($parts['host'])) {
            return null;
        }

        if ($dnsStatus === 'ok' && filled($expectedIp) && ! DnsRecordHints::isPublicAddress($expectedIp)) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port.'/favicon.ico';
    }

    private static function isPrivateHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ! DnsRecordHints::isPublicAddress($host);
        }

        if ($host === 'localhost') {
            return true;
        }

        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        if (preg_match('/(?:^|\.)(\d{1,3}(?:[.-]\d{1,3}){3})\.(?:sslip|nip)\.io$/', $host, $matches) === 1) {
            $embeddedIp = str_replace('-', '.', $matches[1]);

            return filter_var($embeddedIp, FILTER_VALIDATE_IP) === false
                || ! DnsRecordHints::isPublicAddress($embeddedIp);
        }

        return false;
    }
}
