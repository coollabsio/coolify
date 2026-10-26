<?php

use Illuminate\Http\Request;

/**
 * The client IP for rate limits, allow-lists, and audit logs. Behind Cloudflare, $request->ip()
 * is the Cloudflare edge, so a valid CF-Connecting-IP is used: on cloud, and on self-hosted
 * instances when the request comes from a Cloudflare IP. A client that connects directly
 * cannot set the client IP with the header.
 */
function client_ip(Request $request): string
{
    $ip = (string) $request->ip();
    $cloudflareIp = $request->header('CF-Connecting-IP');

    if (is_string($cloudflareIp) && filter_var($cloudflareIp, FILTER_VALIDATE_IP) !== false && (isCloud() || isCloudflareIp($ip))) {
        return $cloudflareIp;
    }

    return $ip;
}
