<?php

namespace App\Support;

use App\Models\Server;
use Illuminate\Support\Collection;

/**
 * HTTP origin exposure through Cloudflare Tunnel (distinct from SSH cloudflared).
 */
class CloudflareHttpTunnel
{
    public const CFARGO_SUFFIX = '.cfargotunnel.com';

    public const ORIGIN_SERVICE = 'http://127.0.0.1:80';

    public const CATCH_ALL_SERVICE = 'http_status:404';

    public static function cnameTarget(?string $tunnelId, ?string $storedCname = null): ?string
    {
        if (filled($storedCname)) {
            return strtolower(rtrim((string) $storedCname, '.'));
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
        if ($server->relationLoaded('settings')) {
            $settings = $server->settings;
        } elseif (! $server->exists) {
            return null;
        } else {
            $settings = $server->settings;
        }

        return self::cnameTarget(
            $settings?->cloudflare_http_tunnel_id,
            $settings?->cloudflare_http_tunnel_cname,
        );
    }

    /**
     * Rewrite resource URLs to http:// for proxy labels when Cloudflare terminates TLS.
     *
     * @param  iterable<int, string>  $domains
     * @return Collection<int, string>
     */
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

    public static function redactSecrets(string $message): string
    {
        $redacted = preg_replace('/eyJ[A-Za-z0-9_\-\.]+/', '[redacted]', $message) ?? $message;

        return preg_replace('/\b[A-Za-z0-9_\-]{40,}\b/', '[redacted]', $redacted) ?? $redacted;
    }
}
