<?php

use Illuminate\Http\Request;

/**
 * The client IP for rate limits, allow-lists, and audit logs.
 *
 * TrustProxies trusts every peer, so a client that connects directly to the dashboard port could
 * set $request->ip() with X-Forwarded-For. The TCP peer (REMOTE_ADDR) decides what to trust:
 * - A public peer is the client. Forwarded headers are ignored, except CF-Connecting-IP when the
 *   peer is a Cloudflare edge.
 * - A private or loopback peer is a local reverse proxy (coolify-proxy, cloudflared, a Docker
 *   network), so the address that proxy forwarded is used. CF-Connecting-IP is used only when
 *   that address is a Cloudflare edge.
 * On cloud, which runs behind Cloudflare, a valid CF-Connecting-IP is always used.
 */
function client_ip(Request $request): string
{
    $cloudflareIp = $request->header('CF-Connecting-IP');
    $cloudflareIp = is_string($cloudflareIp) && filter_var($cloudflareIp, FILTER_VALIDATE_IP) !== false ? $cloudflareIp : null;

    $peer = (string) $request->server('REMOTE_ADDR');
    $hop = isLocalProxyIp($peer) ? (string) $request->ip() : $peer;

    if ($cloudflareIp !== null && (isCloud() || isCloudflareIp($hop))) {
        return $cloudflareIp;
    }

    return $hop;
}

/**
 * Whether an address is loopback, private, or link-local (IPv4, IPv6, or IPv4-mapped IPv6): a
 * reverse proxy on the same host or Docker network rather than a client on the internet.
 */
function isLocalProxyIp(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $packed = inet_pton($ip);
    if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
        $ip = inet_ntop(substr($packed, 12));
    }

    return ipMatch($ip, [
        '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16',
        '::1/128', 'fc00::/7', 'fe80::/10',
    ]);
}
