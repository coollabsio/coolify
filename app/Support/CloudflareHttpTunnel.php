<?php

namespace App\Support;

use App\Models\Server;
use Illuminate\Support\Collection;

class CloudflareHttpTunnel
{
    public const CFARGO_SUFFIX = '.cfargotunnel.com';

    public static function cnameTarget(?string $tunnelId, ?string $storedCname = null): ?string
    {
        if (filled($storedCname)) {
            $normalized = strtolower(rtrim((string) $storedCname, '.'));
            if (! str_contains($normalized, '.')) {
                return $normalized.self::CFARGO_SUFFIX;
            }

            return $normalized;
        }

        if (filled($tunnelId)) {
            return strtolower(trim((string) $tunnelId)).self::CFARGO_SUFFIX;
        }

        return null;
    }

    public static function isCfargoTarget(?string $target): bool
    {
        if (! is_string($target) || $target === '') {
            return false;
        }

        $normalized = strtolower(rtrim($target, '.'));

        return $normalized === 'cfargotunnel.com' || str_ends_with($normalized, self::CFARGO_SUFFIX);
    }

    public static function mismatchGuidance(?string $cname = null): string
    {
        $target = $cname ?: '<tunnel-id>'.self::CFARGO_SUFFIX;

        return "This server publishes through Cloudflare Tunnel. Create a proxied CNAME to {$target}. Do not create an A record to the server public IP, and do not open ports 80/443.";
    }

    public static function successMessage(?string $cname = null): string
    {
        if (filled($cname)) {
            return "DNS points at Cloudflare Tunnel ({$cname}).";
        }

        return 'DNS points at Cloudflare Tunnel (CNAME to *.cfargotunnel.com or Cloudflare proxy IPs).';
    }

    public static function fromServer(Server $server): ?string
    {
        $settings = $server->settings;

        return self::cnameTarget(
            null,
            $settings?->cloudflare_http_tunnel_cname,
        );
    }

    public static function httpOriginDomains(iterable $domains, ?Server $server): Collection
    {
        $collection = collect($domains);
        if (! $server?->isCloudflareHttpTunnel()) {
            return $collection;
        }

        return $collection->map(function ($domain) {
            $domain = (string) $domain;
            if (str_starts_with(strtolower($domain), 'https://')) {
                return 'http://'.substr($domain, 8);
            }

            return $domain;
        });
    }
}
