<?php

use Illuminate\Http\Request;

function auth_rate_limit_ip(Request $request): string
{
    $cloudflareIp = $request->header('CF-Connecting-IP');

    if (isCloud() && is_string($cloudflareIp) && filter_var($cloudflareIp, FILTER_VALIDATE_IP) !== false) {
        return $cloudflareIp;
    }

    return (string) $request->ip();
}
